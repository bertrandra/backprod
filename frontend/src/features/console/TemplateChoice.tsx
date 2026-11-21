import { useState } from 'react';

import { STORY_TEMPLATES, type StoryTemplate } from '@/features/showcase/blocks/templates';
import { Button } from '@/ui/Field';
import { t } from '@/i18n';

/**
 * The templates, each a card with one button.
 *
 * **Replacing what is written takes a second press.** A template overwrites
 * the draft — an afternoon's sentences — and nothing here is optimistic or
 * undoable, so the button that would do it says so first and waits. With an
 * empty draft there is nothing to lose and one press is enough.
 */
export function TemplateChoice({
  replaces,
  onChoose,
}: {
  replaces: boolean;
  onChoose: (template: StoryTemplate) => void;
}) {
  const [asking, setAsking] = useState<string | null>(null);

  return (
    <ul className="grid gap-3" data-testid="story-templates">
      {STORY_TEMPLATES.map((template) => (
        <li
          key={template.id}
          data-template={template.id}
          className="space-y-2 rounded-card border border-line bg-surface p-4 shadow-raise"
        >
          <h3 className="font-medium">{t(template.name)}</h3>
          <p className="text-sm text-muted">{t(template.description)}</p>

          {asking === template.id ? (
            <div className="flex flex-wrap items-center gap-2" role="alert">
              <span className="text-sm">{t("This replaces everything written above.")}</span>
              <Button
                type="button"
                variant="danger"
                data-testid={`confirm-template-${template.id}`}
                onClick={() => {
                  setAsking(null);
                  onChoose(template);
                }}
              >
                {t("Replace it")}</Button>
              <Button type="button" variant="secondary" onClick={() => setAsking(null)}>
                {t("Cancel")}</Button>
            </div>
          ) : (
            <Button
              type="button"
              variant="secondary"
              data-testid={`use-template-${template.id}`}
              onClick={() => (replaces ? setAsking(template.id) : onChoose(template))}
            >
              {t("Use this template")}</Button>
          )}
        </li>
      ))}
    </ul>
  );
}
