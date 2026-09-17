<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Navigation\Domain\Audience;
use App\Navigation\Domain\AudienceMenu;
use App\Navigation\Domain\NavigationSetup;
use App\Navigation\Service\NavigationDesk;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/navigation — the menu setup, replaced whole.
 *
 * The body is `{navigation: {platform_admin: {hidden, hide_empty}, …}}`
 * with **every audience present**: an audience left out would be one the
 * screen forgot, and defaulting it here to "everything" would silently
 * un-hide what somebody chose to hide. Each `hidden` is a list of the
 * shell's entry ids; the platform stores them as words and checks only
 * that they are words.
 */
final class SetNavigationSetupController implements RouteHandler
{
    public function __construct(private readonly NavigationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::NAVIGATION_MANAGE);

        $navigation = JsonBody::of($request)->requiredObject('navigation');
        $menus = [];

        foreach (Audience::all() as $audience) {
            $menu = $navigation->{$audience} ?? null;

            if (!$menu instanceof \stdClass) {
                throw self::invalid('navigation.' . $audience, 'is required and must be a JSON object');
            }

            $hidden = $menu->hidden ?? null;
            $hideEmpty = $menu->hide_empty ?? null;

            if (!is_array($hidden) || !array_is_list($hidden)) {
                throw self::invalid('navigation.' . $audience . '.hidden', 'must be a list of entry ids');
            }

            if (!is_bool($hideEmpty)) {
                throw self::invalid('navigation.' . $audience . '.hide_empty', 'must be a boolean');
            }

            try {
                $menus[$audience] = AudienceMenu::fromArray(['hidden' => $hidden, 'hide_empty' => $hideEmpty]);
            } catch (InvalidArgumentException $e) {
                throw self::invalid('navigation.' . $audience . '.hidden', $e->getMessage());
            }
        }

        $setup = $this->desk->replace($context->identity, NavigationSetup::of($menus));

        return new JsonResponse(['navigation' => $setup->toArray()], 200);
    }

    private static function invalid(string $field, string $requirement): BadRequestException
    {
        return new BadRequestException(
            'VALIDATION_FAILED',
            'The request body is not valid.',
            ['field' => $field, 'requirement' => $requirement],
        );
    }
}
