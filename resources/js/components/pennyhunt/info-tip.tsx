import { Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

/**
 * Small info glyph with an explainer tooltip — used to make every KPI on the
 * ticker page self-documenting.
 */
export function InfoTip({ children }: { children: ReactNode }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    tabIndex={-1}
                    className="inline-flex cursor-help align-middle text-muted-foreground/60 transition-colors hover:text-foreground"
                    aria-label="What is this?"
                >
                    <Info className="size-3.5" />
                </button>
            </TooltipTrigger>
            <TooltipContent
                side="top"
                className="max-w-xs border border-border bg-popover leading-relaxed text-popover-foreground shadow-lg"
            >
                {children}
            </TooltipContent>
        </Tooltip>
    );
}
