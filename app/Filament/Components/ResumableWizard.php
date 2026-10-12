<?php

namespace App\Filament\Components;

use Closure;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Js;
use LogicException;

/**
 * Native Wizard whose completed steps stay reachable and marked done after going back (#59 F69).
 *
 * The native header only unlocks and checks steps before the current one. This keeps the native
 * markup and actions, tracks the furthest saved step, and makes a forward header jump validate
 * and save the current step first, exactly like Save and continue. Every save reports the
 * recomputed saved progress back, so steps reopened by an earlier answer lose their done state.
 */
class ResumableWizard extends Wizard
{
    protected int|Closure $reachedStep = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extraAlpineAttributes(fn (ResumableWizard $component): array => [
            'x-init' => 'window.talaResumableWizard($el._x_dataStack?.[0] ?? $data, $watch, '.Js::from([
                'key' => $component->getKey(),
                'isSkippable' => $component->isSkippable(),
                'reachedStepIndex' => $component->getReachedStep() - 1,
            ])->toHtml().')',
            'x-on:tala-wizard-jump.window' => '$event.detail.key === '.Js::from($component->getKey())->toHtml().' && completeJump($event.detail.step)',
            'x-on:tala-wizard-reached.window' => '$event.detail.key === '.Js::from($component->getKey())->toHtml().' && syncReachedStep($event.detail.reachedStepIndex)',
        ], merge: true);
    }

    /** The furthest step the saved draft allows, one-based. */
    public function reachedStep(int|Closure $step): static
    {
        $this->reachedStep = $step;

        return $this;
    }

    public function getReachedStep(): int
    {
        return max(1, (int) $this->evaluate($this->reachedStep));
    }

    #[ExposedLivewireMethod]
    public function nextStep(int $currentStepIndex): void
    {
        parent::nextStep($currentStepIndex);

        $this->dispatchReachedStep();
    }

    #[ExposedLivewireMethod]
    public function jumpToStep(int $currentStepIndex, int $targetStepIndex): void
    {
        $steps = array_values($this->getChildSchema()->getComponents());
        $currentStep = $steps[$currentStepIndex] ?? null;
        $targetStep = $steps[$targetStepIndex] ?? null;

        if ((! $currentStep instanceof Step) || (! $targetStep instanceof Step) || ($targetStepIndex <= $currentStepIndex)) {
            return;
        }

        if (! $this->isSkippable()) {
            try {
                $currentStep->callBeforeValidation();
                $currentStep->getChildSchema()->validate();
                $currentStep->callAfterValidation();
            } catch (Halt) {
                return;
            }

            $reachedStepIndex = $this->dispatchReachedStep();

            if ($targetStepIndex > $reachedStepIndex) {
                $pendingStep = $steps[$reachedStepIndex] ?? null;

                Notification::make()
                    ->warning()
                    ->title('Finish the earlier steps first')
                    ->body($pendingStep instanceof Step
                        ? "Complete “{$pendingStep->getLabel()}” before opening “{$targetStep->getLabel()}”. Changing an earlier answer can reopen later steps."
                        : 'Complete the earlier steps first. Changing an earlier answer can reopen later steps.')
                    ->send();

                return;
            }
        }

        $this->currentStepIndex($targetStepIndex);

        $this->getLivewire()->dispatch('tala-wizard-jump', key: $this->getKey(), step: $targetStep->getKey());
    }

    /** Report the recomputed saved progress to the header and return its zero-based index. */
    protected function dispatchReachedStep(): int
    {
        $reachedStepIndex = $this->getReachedStep() - 1;

        $this->getLivewire()->dispatch('tala-wizard-reached', key: $this->getKey(), reachedStepIndex: $reachedStepIndex);

        return $reachedStepIndex;
    }

    public function toEmbeddedHtml(): string
    {
        $html = parent::toEmbeddedHtml();

        if ($this->isHeaderHidden()) {
            return $html;
        }

        foreach ([
            '/getStepIndex\(step\) (?:>|&gt;) (\d+)/' => 'isStepCompleted($1)',
            '/getStepIndex\(step\) (?:<=|&lt;=) (\d+)/' => '! isStepCompleted($1)',
            '/x-on:click="step = ([^"]+)"/' => 'x-on:click="requestStep($1)"',
        ] as $pattern => $replacement) {
            $html = (string) preg_replace($pattern, $replacement, $html, count: $count);

            if ($count === 0) {
                throw new LogicException('The native Wizard header markup changed; review ResumableWizard before upgrading Filament.');
            }
        }

        return $html;
    }
}
