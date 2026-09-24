import { useRef } from 'react';

import { useUploadShowcaseAsset } from '@/queries/showcase';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { t } from '@/i18n';

/**
 * The picture one band carries.
 *
 * **Uploading is not choosing.** The bytes go up on their own request and
 * the band keeps the id; which band carries which picture is part of the
 * story and is saved with the rest of it. Two acts, because an upload that
 * also wrote the story would save four half-finished bands the moment
 * somebody picked a file.
 *
 * The preview is the **public address** the page will use, which is the
 * point: what the console shows here is the thing a stranger will get, not
 * a local object URL that exists for one tab and proves nothing. It only
 * resolves once the page is published — before that the frame is honest
 * about it rather than showing a broken image.
 */
export function BandPicture({
  productId,
  image,
  assetId,
  onChoose,
}: {
  productId: string;
  /**
   * Where the picture lives, **composed by the server** and carried on the
   * block. Not built here: a URL written by hand in a screen is a second
   * place the route is spelled, and the one that goes stale. Null until
   * the page is published, which is what makes the picture readable.
   */
  image: string | null;
  assetId: string | null;
  onChoose: (assetId: string | null) => void;
}) {
  const upload = useUploadShowcaseAsset(productId);
  const input = useRef<HTMLInputElement>(null);

  return (
    <div className="space-y-2" data-testid="band-picture">
      <div className="flex flex-wrap items-center gap-2">
        <input
          ref={input}
          type="file"
          // What the server will actually take. A browser filter is a
          // courtesy — the bytes are sniffed and re-checked either way.
          accept="image/png,image/jpeg,image/gif,image/webp"
          className="sr-only"
          data-testid="pick-picture"
          onChange={(event) => {
            const file = event.target.files?.[0];

            if (file !== undefined) {
              upload.mutate(file, { onSuccess: (asset) => onChoose(asset.id) });
            }

            // Cleared, so choosing the same file twice fires twice: a
            // re-upload after a failure is exactly when somebody picks the
            // same file again.
            event.target.value = '';
          }}
        />

        <Button
          type="button"
          variant="secondary"
          pending={upload.isPending}
          onClick={() => input.current?.click()}
        >
          {assetId === null ? t("Add a picture") : t("Replace the picture")}
        </Button>

        {assetId !== null && (
          <Button type="button" variant="secondary" data-testid="clear-picture" onClick={() => onChoose(null)}>
            {t("Remove the picture")}</Button>
        )}
      </div>

      {upload.error !== null && <ErrorSurface error={upload.error} />}

      {assetId !== null &&
        (image !== null ? (
          <img
            src={image}
            alt=""
            data-testid="picture-preview"
            className="max-h-40 rounded-control border border-line"
          />
        ) : (
          <p data-testid="picture-not-public-yet" className="text-xs text-muted">
            {t("The picture is uploaded. It can only be shown here once the page is published, because that is what makes it readable.")}</p>
        ))}
    </div>
  );
}
