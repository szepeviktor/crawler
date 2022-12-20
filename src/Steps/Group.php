<?php

namespace Crwlr\Crawler\Steps;

use Crwlr\Crawler\Input;
use Crwlr\Crawler\Loader\LoaderInterface;
use Crwlr\Crawler\Output;
use Crwlr\Crawler\Result;
use Exception;
use Generator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class Group extends BaseStep
{
    /**
     * @var StepInterface[]
     */
    private array $steps = [];

    private ?LoaderInterface $loader = null;

    /**
     * @param Input $input
     * @return Generator<Output>
     * @throws Exception
     */
    public function invokeStep(Input $input): Generator
    {
        $combinedOutput = [];

        $input = $this->prepareInput($input);

        if (!$input) {
            return;
        }

        foreach ($this->steps as $key => $step) {
            foreach ($step->invokeStep($input) as $nthOutput => $output) {
                if (method_exists($step, 'callUpdateInputUsingOutput')) {
                    $input = $step->callUpdateInputUsingOutput($input, $output);
                }

                if ($this->cascades() && $step->cascades()) {
                    $stepKey = $step->getResultKey() ?? $key;

                    $combinedOutput = $this->addOutputToCombinedOutputs(
                        $output->get(),
                        $combinedOutput,
                        $stepKey,
                        $nthOutput,
                    );
                }
            }
        }

        if ($this->cascades()) {
            yield from $this->prepareCombinedOutputs($combinedOutput, $input);
        }
    }

    public function addsToOrCreatesResult(): bool
    {
        if (parent::addsToOrCreatesResult()) {
            return true;
        }

        foreach ($this->steps as $step) {
            if ($step->addsToOrCreatesResult()) {
                return true;
            }
        }

        return false;
    }

    public function addStep(string|StepInterface $stepOrResultKey, ?StepInterface $step = null): self
    {
        if (is_string($stepOrResultKey) && $step === null) {
            throw new InvalidArgumentException('No StepInterface object provided');
        } elseif ($stepOrResultKey instanceof StepInterface) {
            $step = $stepOrResultKey;
        }

        if ($this->logger instanceof LoggerInterface) {
            $step->addLogger($this->logger);
        }

        if (method_exists($step, 'addLoader') && $this->loader instanceof LoaderInterface) {
            $step->addLoader($this->loader);
        }

        if (is_string($stepOrResultKey) && !isset($this->steps[$stepOrResultKey])) {
            $this->steps[$stepOrResultKey] = $step;
        } else {
            $this->steps[] = $step;
        }

        return $this;
    }

    public function addLogger(LoggerInterface $logger): static
    {
        parent::addLogger($logger);

        foreach ($this->steps as $step) {
            $step->addLogger($logger);
        }

        return $this;
    }

    public function addLoader(LoaderInterface $loader): self
    {
        $this->loader = $loader;

        foreach ($this->steps as $step) {
            if (method_exists($step, 'addLoader')) {
                $step->addLoader($loader);
            }
        }

        return $this;
    }

    /**
     * @throws Exception
     */
    private function prepareInput(Input $input): ?Input
    {
        $input = $this->getInputKeyToUse($input);

        if (!$this->uniqueInput || $this->inputOrOutputIsUnique($input)) {
            return $this->addResultToInputIfAnyResultKeysDefined($input);
        }

        return null;
    }

    /**
     * If this group combines the output, there are result keys and there is no Result object created before invoking
     * the steps, add one. Because otherwise multiple Result objects will be created.
     *
     * @param Input $input
     * @return Input
     */
    private function addResultToInputIfAnyResultKeysDefined(Input $input): Input
    {
        if ($this->addsToOrCreatesResult() && !$input->result) {
            $input = new Input($input->get(), new Result());
        }

        return $input;
    }

    /**
     * @param mixed[] $combined
     * @return mixed[]
     */
    private function addOutputToCombinedOutputs(
        mixed $output,
        array $combined,
        int|string $stepKey,
        int $nthOutput,
    ): array {
        if (is_array($output)) {
            foreach ($output as $key => $value) {
                if (is_int($stepKey) && is_string($key)) {
                    $combined[$nthOutput][$key][] = $value;
                } else {
                    $combined[$nthOutput][$stepKey][$key][] = $value;
                }
            }
        } else {
            $combined[$nthOutput][$stepKey][] = $output;
        }

        return $combined;
    }

    /**
     * @param mixed[] $combinedOutputs
     * @param Input $input
     * @return Generator<Output>
     * @throws Exception
     */
    private function prepareCombinedOutputs(array $combinedOutputs, Input $input): Generator
    {
        $result = $input->result;

        foreach ($combinedOutputs as $combinedOutput) {
            $outputData = $this->normalizeCombinedOutputs($combinedOutput);

            if ($this->passesAllFilters($outputData)) {
                if ($this->keepInputData === true) {
                    $outputData = $this->addInputDataToOutputData($input->get(), $outputData);
                }

                $output = new Output($outputData, $result);

                if ($this->uniqueOutput !== false && !$this->inputOrOutputIsUnique($output)) {
                    continue;
                }

                $this->addOutputDataToResult($outputData, $result);

                yield $output;
            }
        }
    }

    /**
     * Normalize combined outputs
     *
     * When adding outputs to combined output during step invocation, it always adds as arrays.
     * Here it unwraps all array properties with just one element to have just that one element as value.
     *
     * @param mixed[] $combinedOutputs
     * @return mixed[]
     */
    private function normalizeCombinedOutputs(array $combinedOutputs): array
    {
        return array_map(function ($output) {
            return count($output) === 1 ? reset($output) : $output;
        }, $combinedOutputs);
    }
}
