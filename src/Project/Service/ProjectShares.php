<?php

declare(strict_types=1);

namespace App\Project\Service;

use App\Entitlement\Domain\Coverage;
use App\Entitlement\Domain\EntitlementRepository;
use App\Product\Domain\ProductRepository;
use App\Project\Domain\ProjectRepository;
use App\Project\Domain\ProjectShare;
use App\Project\Domain\ProjectShareRepository;
use App\Project\Domain\Reach;
use App\Project\Domain\SharedProject;
use App\Project\Domain\ShareToken;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use SensitiveParameter;

/**
 * Share links: a project shown, read-only, to somebody with no account
 * (ADR-073).
 *
 * The private half goes through {@see ProjectWorkspace::get()}, so a link can
 * only be made, listed or revoked on a project the caller reaches — the same
 * `Reach` every other project operation is held to (ADR-064), and the same
 * "not found" for a project that is somebody else's.
 *
 * The public half takes nothing but the token. Everything it reads is scoped
 * by what the link says — its tenant, its product, its project — and nothing
 * by what the request says, because a stranger's request has nothing in it
 * worth believing.
 */
final class ProjectShares
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 365;

    /** Live links one project may have at once: enough for a person, not a mailing list. */
    public const MAX_LIVE_PER_PROJECT = 50;

    public const DISPLAY_NAME_MAX_LENGTH = 80;
    public const RECIPIENT_LABEL_MAX_LENGTH = 120;

    public function __construct(
        private readonly ProjectWorkspace $workspace,
        private readonly ProjectShareRepository $shares,
        private readonly ProjectRepository $projects,
        private readonly EntitlementRepository $entitlements,
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * A new link, and the only moment its URL exists outside the reader's
     * browser.
     *
     * @return array{share: ProjectShare, url: string}
     */
    public function create(
        string $tenantId,
        string $productId,
        string $callerId,
        bool $seesEverything,
        string $projectId,
        ?string $displayName,
        ?string $recipientLabel,
        ?int $days,
    ): array {
        $days ??= self::DEFAULT_DAYS;

        if ($days < 1 || $days > self::MAX_DAYS) {
            // Refused rather than clamped: a client asking for two years
            // should learn that it did not get them.
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'expires_in_days', 'requirement' => sprintf('must be between 1 and %d', self::MAX_DAYS)],
            );
        }

        $project = $this->workspace->get($tenantId, $productId, $callerId, $seesEverything, $projectId);

        // Asked after the project, so somebody probing ids learns nothing
        // from which refusal they get: an unknown project is 404 whatever the
        // product is.
        $product = $this->products->find($productId);
        $appUrl = $product?->appUrl;

        if ($product === null || $appUrl === null || $appUrl === '') {
            throw new ConflictException(
                'SHARE_HAS_NO_VIEWER',
                'This product has no page of its own to show a shared project on.',
            );
        }

        if ($this->shares->countLiveFor($project) >= self::MAX_LIVE_PER_PROJECT) {
            throw new ConflictException(
                'SHARE_LIMIT_REACHED',
                'This project already has as many live links as it may have. Revoke one first.',
                ['limit' => self::MAX_LIVE_PER_PROJECT],
            );
        }

        $token = ShareToken::mint();

        $share = $this->shares->create(
            $project,
            ShareToken::hash($token),
            $displayName,
            $recipientLabel,
            $days,
            $callerId,
        );

        return ['share' => $share, 'url' => self::addressOf($appUrl, $product->code, $token)];
    }

    /**
     * @return list<ProjectShare>
     */
    public function list(string $tenantId, string $productId, string $callerId, bool $seesEverything, string $projectId): array
    {
        return $this->shares->listFor(
            $this->workspace->get($tenantId, $productId, $callerId, $seesEverything, $projectId),
        );
    }

    /**
     * Revoking twice is not an error: the second asks for what is already
     * true, and keeps the first date and the first actor.
     */
    public function revoke(
        string $tenantId,
        string $productId,
        string $callerId,
        bool $seesEverything,
        string $projectId,
        string $shareId,
    ): void {
        $project = $this->workspace->get($tenantId, $productId, $callerId, $seesEverything, $projectId);
        $share = $this->shares->find($project, $shareId);

        if ($share === null) {
            throw new NotFoundException('Share link not found.', [], 'SHARE_NOT_FOUND');
        }

        $this->shares->revoke($share, $callerId);
    }

    /**
     * The public read: one answer for every way a link can fail.
     *
     * Malformed, unknown, expired, revoked; a project deleted, or whose
     * holder was erased, or whose holder no subscription covers any more —
     * all of them `SHARE_NOT_FOUND`. "This link existed and was withdrawn" is
     * already information about somebody.
     */
    public function open(#[SensitiveParameter] string $token): SharedProject
    {
        if (!ShareToken::isWellFormed($token)) {
            throw self::notFound();
        }

        $share = $this->shares->findLiveByTokenHash(ShareToken::hash($token));

        if ($share === null) {
            throw self::notFound();
        }

        // Everything, because the reach was decided when the link was made
        // by somebody who had it; the scope is the link's own, never the
        // request's. The holder's coverage below is what keeps it honest.
        $project = $this->projects->find($share->tenantId, $share->productId, $share->projectId, Reach::everything());

        if ($project === null || $project->isDeleted() || $project->holderUserId === null) {
            throw self::notFound();
        }

        // A link is a window onto the workshop, and the workshop is
        // suspended when whoever pays for the work is no longer covered
        // (ADR-060). It answers again the moment they are, because nothing
        // about the link changed.
        if ($this->entitlements->coverageFor($share->tenantId, $share->productId, $project->holderUserId) !== Coverage::COVERED) {
            throw self::notFound();
        }

        $this->shares->recordOpening($share);

        return new SharedProject($share, $project);
    }

    /**
     * The product's page, with the token in the fragment (ADR-073 §2).
     *
     * A fragment is never sent to the server that serves the page, so the
     * token is in no access log of the product's host and in no `Referer`.
     * `product` is in the query because it is the handover every product
     * already reads (ADR-051 §3).
     */
    private static function addressOf(string $appUrl, string $code, #[SensitiveParameter] string $token): string
    {
        $base = parse_url($appUrl, PHP_URL_PATH) === null ? $appUrl . '/' : $appUrl;

        return $base . '?product=' . rawurlencode($code) . '#share=' . $token;
    }

    private static function notFound(): NotFoundException
    {
        return new NotFoundException('This link does not open anything.', [], 'SHARE_NOT_FOUND');
    }
}
