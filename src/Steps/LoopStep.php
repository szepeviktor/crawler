<?php

namespace Crwlr\Crawler\Steps;

use AppendIterator;
use Closure;
use Crwlr\Crawler\Input;
use Crwlr\Crawler\Output;
use Generator;
use NoRewindIterator;
use Psr\Log\LoggerInterface;

final class LoopStep implements StepInterface
{
    private int $maxIterations = 1000;
    private null|Closure|StepInterface $transformer = null;
    private null|Closure $withInput = null;
    //private null|Closure $stopIf = null;

    public function __construct(private StepInterface $step)
    {
    }

    public function invokeStep(Input $input): Generator
    {
        $inputs = [$input];

        for ($i = 0; $i < $this->maxIterations && !empty($inputs); $i++) {
            $outputs = new AppendIterator();

            foreach ($inputs as $input) {
                $outputs->append(new NoRewindIterator($this->step->invokeStep($input)));
            }

            $inputsForNextIteration = [];

            foreach ($outputs as $output) {
                yield $output;

                if ($this->withInput) {
                    $newInputValue = $this->withInput->call($this, $inputs[0], $output);

                    if ($newInputValue === null) {
                        $inputsForNextIteration = [];
                    } else {
                        $inputsForNextIteration = [new Input($newInputValue, $inputs[0]->result)];
                    }
                } else {
                    $newInput = $this->outputToInput($output);

                    if ($newInput !== null) {
                        $inputsForNextIteration[] = $newInput;
                    }
                }
            }

            $inputs = $inputsForNextIteration;

            if (!$this->withInput) {
                $inputs = empty($inputsForNextIteration) ? [] : [end($inputsForNextIteration)];
            }
        }
    }

    public function maxIterations(int $count): self
    {
        $this->maxIterations = $count;

        return $this;
    }

    public function withInput(Closure $closure): self
    {
        $this->withInput = $closure;

        return $this;
    }

    // TODO
//    public function stopIf(Closure $closure): self
//    {
//        $this->stopIf = $closure;
//
//        return $this;
//    }

    public function setResultKey(string $key): static
    {
        $this->step->setResultKey($key);

        return $this;
    }

    public function getResultKey(): ?string
    {
        return $this->step->getResultKey();
    }

    public function useInputKey(string $key): static
    {
        $this->step->useInputKey($key);

        return $this;
    }

    public function dontYield(): static
    {
        $this->step->dontYield();

        return $this;
    }

    /**
     * Callback that is called in a step group to adapt the input for further steps
     *
     * In groups all the steps are called with the same Input, but with this callback it's possible to adjust the input
     * for the following steps.
     */
    public function updateInputUsingOutput(Closure $closure): static
    {
        if (method_exists($this->step, 'updateInputUsingOutput')) {
            $this->step->updateInputUsingOutput($closure);
        }

        return $this;
    }

    /**
     * If the user set a callback to update the input (see above) => call it.
     */
    public function callUpdateInputUsingOutput(Input $input, Output $output): Input
    {
        if (method_exists($this->step, 'callUpdateInputUsingOutput')) {
            return $this->step->callUpdateInputUsingOutput($input, $output);
        }

        return $input;
    }

    public function transformOutputToInput(Closure|StepInterface $transformer): self
    {
        $this->transformer = $transformer;

        return $this;
    }

    public function addLogger(LoggerInterface $logger): static
    {
        $this->step->addLogger($logger);

        return $this;
    }

    private function outputToInput(Output $output): ?Input
    {
        if ($this->transformer) {
            if ($this->transformer instanceof Closure) {
                $transformerResult = $this->transformer->call($this->step, $output);
            } else {
                $transformerResult = $this->transformer->invokeStep(new Input($output))->current();
            }

            return $transformerResult === null ? null : new Input($transformerResult);
        }

        return new Input($output);
    }
}
