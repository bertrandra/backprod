import { useRef, useState } from 'react';

import {
  useAssets,
  useCreateAssetLink,
  useDeleteAsset,
  useRequestExport,
  useUploadAsset,
} from '@/queries/assets';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';

/**
 * `workspace.assets` — files on a project.
 *
 * Two rules from the contract shape this panel.
 *
 * **The bytes never pass through this application.** Downloading asks for a
 * signed link and sends the browser to it, so a 200 MB file is a conversation
 * between the browser and storage. Fetching it here to make a blob URL would put
 * it in this tab's memory for no gain, and ESLint forbids the `fetch` anyway.
 *
 * **The type the browser claims is not believed.** The API sniffs the bytes and
 * answers with what it found, so an upload's row comes from the response — a row
 * assembled here from the `File` would show `image/png` for a renamed script.
 */
export function AssetsPanel({ projectId }: { projectId: string }) {
  const assets = useAssets(projectId);
  const upload = useUploadAsset(projectId);
  const remove = useDeleteAsset(projectId);
  const link = useCreateAssetLink();
  const requestExport = useRequestExport(projectId);

  const fileInput = useRef<HTMLInputElement>(null);
  const [confirming, setConfirming] = useState<string | null>(null);

  const download = (assetId: string) => {
    link.mutate(assetId, {
      onSuccess: ({ url }) => {
        // Navigation, not a fetch: the browser follows the signed link and the
        // download happens outside this application entirely.
        window.location.assign(url);
      },
    });
  };

  return (
    <section className="space-y-3" data-testid="assets-panel">
      <div className="flex flex-wrap items-center gap-3">
        <h2 className="text-xl font-semibold">{t("Files")}</h2>

        <Button
          type="button"
          variant="secondary"
          pending={requestExport.isPending}
          onClick={() => requestExport.mutate()}
          className="ml-auto"
        >
          {t("Export project")}</Button>
      </div>

      <p className="text-sm text-muted">
        {t("An export is queued rather than produced here — watch it finish in the status strip, then download it from this list.")}</p>

      {requestExport.error !== null && <ErrorSurface error={requestExport.error} />}
      {link.error !== null && <ErrorSurface error={link.error} />}
      {remove.error !== null && <ErrorSurface error={remove.error} />}
      {upload.error !== null && <ErrorSurface error={upload.error} />}

      <div>
        <input
          ref={fileInput}
          id="asset-file"
          type="file"
          className="block w-full text-sm file:mr-3 file:min-h-[44px] file:rounded-control file:border-0 file:bg-inverse file:px-3 file:text-sm file:text-on-inverse"
          onChange={(event) => {
            const file = event.target.files?.[0];

            if (file !== undefined) {
              upload.mutate(file, {
                onSettled: () => {
                  // Cleared either way, so the same file can be chosen again
                  // after a failure — an input that kept it would ignore the
                  // second attempt.
                  if (fileInput.current !== null) {
                    fileInput.current.value = '';
                  }
                },
              });
            }
          }}
        />
      </div>

      {assets.isPending ? (
        <SkeletonRows rows={3} />
      ) : assets.error !== null ? (
        <ErrorSurface error={assets.error} onRetry={() => void assets.refetch()} />
      ) : assets.data.length === 0 ? (
        <EmptyState title={t("No files")} description={t("Upload one, or export the project.")} />
      ) : (
        <ul className="space-y-2">
          {assets.data.map((asset) => (
            <li
              key={asset.id}
              data-asset={asset.id}
              className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm md:flex md:items-center md:gap-3"
            >
              <div className="min-w-0 md:flex-1">
                <p className="truncate font-medium">{asset.filename}</p>
                <p className="text-xs text-muted">
                  {/* The sniffed type and the stored size, both the server's
                      answers. The checksum is shown because it is what makes a
                      corrupted download detectable. */}
                  {t("{type} · {size} bytes · {checksum}", { type: asset.content_type, size: asset.byte_size, checksum: asset.checksum.slice(0, 12) })}
                </p>
              </div>

              <div className="mt-2 flex flex-wrap gap-2 md:mt-0">
                <Button
                  type="button"
                  variant="secondary"
                  pending={link.isPending}
                  onClick={() => download(asset.id)}
                >
                  {t("Download")}</Button>

                {confirming === asset.id ? (
                  <>
                    <Button
                      type="button"
                      variant="danger"
                      pending={remove.isPending}
                      onClick={() =>
                        remove.mutate(asset.id, { onSettled: () => setConfirming(null) })
                      }
                    >
                      {t("Delete for good")}</Button>
                    <Button type="button" variant="secondary" onClick={() => setConfirming(null)}>
                      {t("Keep")}</Button>
                  </>
                ) : (
                  <Button
                    type="button"
                    variant="secondary"
                    onClick={() => setConfirming(asset.id)}
                  >
                    {t("Delete")}</Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
