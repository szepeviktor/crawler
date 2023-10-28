<?php

namespace tests;

use tests\_Stubs\LoaderCollectingStep;
use tests\_Stubs\MultiLoaderCrawler;
use tests\_Stubs\PhantasyLoader;
use Crwlr\Crawler\Crawler;
use Crwlr\Crawler\Exceptions\UnknownLoaderKeyException;
use Crwlr\Crawler\Loader\Http\HttpLoader;
use Crwlr\Crawler\Loader\LoaderInterface;
use Crwlr\Crawler\Logger\CliLogger;
use Crwlr\Crawler\Output;
use Crwlr\Crawler\Result;
use Crwlr\Crawler\Steps\Loading\Http;
use Crwlr\Crawler\Steps\Loading\LoadingStepInterface;
use Crwlr\Crawler\Steps\Step;
use Crwlr\Crawler\Steps\StepInterface;
use Crwlr\Crawler\Stores\Store;
use Crwlr\Crawler\Stores\StoreInterface;
use Crwlr\Crawler\UserAgents\BotUserAgent;
use Crwlr\Crawler\UserAgents\UserAgentInterface;
use Generator;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

function helper_getDummyCrawler(): Crawler
{
    return new class () extends Crawler {
        public function userAgent(): UserAgentInterface
        {
            return new BotUserAgent('FooBot');
        }

        public function loader(UserAgentInterface $userAgent, LoggerInterface $logger): LoaderInterface
        {
            return Mockery::mock(LoaderInterface::class);
        }
    };
}

function helper_getDummyCrawlerWithInputReturningStep(): Crawler
{
    $crawler = helper_getDummyCrawler();

    $step = helper_getInputReturningStep();

    $crawler->addStep($step);

    return $crawler;
}

/** @var TestCase $this */

test(
    'The methods to define UserAgent, Logger and Loader instances are called in construct and the getter methods ' .
    'always return the same instance.',
    function () {
        $crawler = new class () extends Crawler {
            public int $userAgentCalled = 0;

            public int $loggerCalled = 0;

            public int $loaderCalled = 0;

            protected function userAgent(): UserAgentInterface
            {
                $this->userAgentCalled += 1;

                return new class ('FooBot') extends BotUserAgent {
                    public string $testProperty = 'foo';
                };
            }

            protected function logger(): LoggerInterface
            {
                $this->loggerCalled += 1;

                return new class () extends CliLogger {
                    public string $testProperty = 'foo';
                };
            }

            protected function loader(UserAgentInterface $userAgent, LoggerInterface $logger): LoaderInterface
            {
                $this->loaderCalled += 1;

                return new class ($userAgent, null, $logger) extends HttpLoader {
                    public string $testProperty = 'foo';
                };
            }
        };

        expect($crawler->getUserAgent()->testProperty)->toBe('foo'); // @phpstan-ignore-line

        expect($crawler->getLogger()->testProperty)->toBe('foo');  // @phpstan-ignore-line

        expect($crawler->getLoader()->testProperty)->toBe('foo');  // @phpstan-ignore-line

        expect($crawler->userAgentCalled)->toBe(1);

        expect($crawler->loggerCalled)->toBe(1);

        expect($crawler->loaderCalled)->toBe(1);

        $crawler->getUserAgent()->testProperty = 'bar'; // @phpstan-ignore-line

        $crawler->getLogger()->testProperty = 'bar'; // @phpstan-ignore-line

        $crawler->getLoader()->testProperty = 'bar'; // @phpstan-ignore-line

        $crawler->addStep(Http::get()); // adding steps passes on logger and loader, should use the same instances

        expect($crawler->getUserAgent()->testProperty)->toBe('bar');

        expect($crawler->getLogger()->testProperty)->toBe('bar');

        expect($crawler->getLoader()->testProperty)->toBe('bar');  // @phpstan-ignore-line

        expect($crawler->userAgentCalled)->toBe(1);

        expect($crawler->loggerCalled)->toBe(1);

        expect($crawler->loaderCalled)->toBe(1);
    }
);

it('gives you the current memory limit', function () {
    expect(Crawler::getMemoryLimit())->toBeString();
});

it('changes the current memory limit when allowed', function () {
    $currentLimit = Crawler::getMemoryLimit();

    if ($currentLimit === '512M') {
        $newValue = '1G';
    } else {
        $newValue = '512M';
    }

    $setLimitReturnValue = Crawler::setMemoryLimit($newValue);

    if ($setLimitReturnValue === false) {
        expect(Crawler::getMemoryLimit())->toBe($currentLimit);
    } else {
        expect(Crawler::getMemoryLimit())->toBe($newValue);
    }
});

test('You can set a single input for the first step using the input method', function () {
    $crawler = helper_getDummyCrawlerWithInputReturningStep();

    $crawler->input('https://www.example.com');

    $results = helper_generatorToArray($crawler->run());

    expect($results[0]->toArray()['unnamed'])->toBe('https://www.example.com');
});

test('You can set multiple inputs by multiply calling the input method', function () {
    $crawler = helper_getDummyCrawlerWithInputReturningStep();

    $crawler->input('https://www.crwl.io');

    $crawler->input('https://www.otsch.codes');

    $results = helper_generatorToArray($crawler->run());

    expect($results[0]->toArray()['unnamed'])->toBe('https://www.crwl.io');

    expect($results[1]->toArray()['unnamed'])->toBe('https://www.otsch.codes');
});

test('You can set multiple inputs using the inputs (plural) method', function () {
    $crawler = helper_getDummyCrawlerWithInputReturningStep();

    $crawler->inputs(['https://www.crwl.io', 'https://www.otsch.codes']);

    $results = helper_generatorToArray($crawler->run());

    expect($results[0]->toArray()['unnamed'])->toBe('https://www.crwl.io');

    expect($results[1]->toArray()['unnamed'])->toBe('https://www.otsch.codes');
});

test('Initial inputs are reset after the crawler was run', function () {
    $crawler = helper_getDummyCrawlerWithInputReturningStep();

    $crawler->inputs(['https://www.crwl.io', 'https://www.otsch.codes']);

    $results = helper_generatorToArray($crawler->run());

    expect($results)->toHaveCount(2);

    $crawler->input('https://fetzi.dev/');

    $results = helper_generatorToArray($crawler->run());

    expect($results)->toHaveCount(1);
});

test('You can add steps and the Crawler class passes on its Logger and also its Loader if needed', function () {
    $step = Mockery::mock(StepInterface::class);

    $step->shouldReceive('addLogger')->once();

    $crawler = helper_getDummyCrawler();

    $crawler->addStep($step);

    $step = Mockery::mock(LoadingStepInterface::class);

    $step->shouldReceive('addLogger')->once();

    $step->shouldReceive('addLoader')->once();

    $crawler->addStep($step);
});

test('you can define multiple loaders', function () {
    $crawler = new MultiLoaderCrawler();

    expect($crawler->getLoader())->toBeArray();

    expect($crawler->getLoader())->toHaveCount(3);

    expect($crawler->getLoader())->toHaveKey('http');

    expect($crawler->getLoader()['http'])->toBeInstanceOf(HttpLoader::class); // @phpstan-ignore-line

    expect($crawler->getLoader())->toHaveKey('phantasy');

    expect($crawler->getLoader()['phantasy'])->toBeInstanceOf(PhantasyLoader::class); // @phpstan-ignore-line

    expect($crawler->getLoader())->toHaveKey('phantasy2');

    expect($crawler->getLoader()['phantasy2'])->toBeInstanceOf(PhantasyLoader::class); // @phpstan-ignore-line
});

it('passes each of its loaders one by one to its steps', function () {
    $step = new LoaderCollectingStep();

    (new MultiLoaderCrawler())->addStep($step);

    expect($step->loaders)->toHaveCount(3);

    expect($step->loaders[0])->toBeInstanceOf(HttpLoader::class);

    expect($step->loaders[1])->toBeInstanceOf(PhantasyLoader::class);

    expect($step->loaders[2])->toBeInstanceOf(PhantasyLoader::class);
});

it('passes on all the loaders to a group step which by default passes all of them to child loading steps', function () {
    $crawler = new MultiLoaderCrawler();

    $step = new LoaderCollectingStep();

    $crawler
        ->addStep(
            Crawler::group()
                ->addStep(Http::get())
                ->addStep($step)
        );

    expect($step->loaders)->toHaveCount(3);

    expect($step->loaders[0])->toBeInstanceOf(HttpLoader::class);

    expect($step->loaders[1])->toBeInstanceOf(PhantasyLoader::class);

    expect($step->loaders[2])->toBeInstanceOf(PhantasyLoader::class);
});

it('passes only a certain loader when user choses one by calling useLoader() on a step', function () {
    $step = new LoaderCollectingStep();

    (new MultiLoaderCrawler())->addStep($step->useLoader('http'));

    expect($step->loaders)->toHaveCount(1);

    expect($step->loaders[0])->toBeInstanceOf(HttpLoader::class);
});

it('passes only a certain loader when user choses one by calling useLoader() on a step inside a group', function () {
    $crawler = new MultiLoaderCrawler();

    $step = new LoaderCollectingStep();

    $crawler
        ->addStep(
            Crawler::group()
                ->addStep(Http::get())
                ->addStep($step->useLoader('http'))
        );

    expect($step->loaders)->toHaveCount(1);

    expect($step->loaders[0])->toBeInstanceOf(HttpLoader::class);
});

it(
    'throws an UnknownLoaderKeyException when user wants to chose a loader that was not defined in the crawlers ' .
    'loader() method',
    function () {
        $step = new LoaderCollectingStep();

        (new MultiLoaderCrawler())->addStep($step->useLoader('https'));
    }
)->throws(UnknownLoaderKeyException::class);

test('You can add steps and they are invoked when the Crawler is run', function () {
    $step1 = helper_getValueReturningStep('step1 output')->addToResult('step1');

    $step2 = helper_getValueReturningStep('step2 output')->addToResult('step2');

    $crawler = helper_getDummyCrawler()
        ->addStep($step1)
        ->addStep($step2);

    $crawler->input('randomInput');

    $results = helper_generatorToArray($crawler->run());

    expect($results)->toHaveCount(1);

    expect($results[0]->toArray())->toBe(['step1' => 'step1 output', 'step2' => 'step2 output']);
});

it('resets the initial inputs and calls the resetAfterRun method of all its steps', function () {
    $step = helper_getInputReturningStep()
        ->uniqueOutputs();

    $crawler = helper_getDummyCrawler()
        ->addStep('foo', $step)
        ->inputs(['input1', 'input1', 'input2']);

    $results = helper_generatorToArray($crawler->run());

    expect($results)->toHaveCount(2);

    expect($results[0]->toArray())->toBe(['foo' => 'input1']);

    expect($results[1]->toArray())->toBe(['foo' => 'input2']);

    $crawler->inputs(['input1', 'input3']);

    $results = helper_generatorToArray($crawler->run());

    expect($results)->toHaveCount(2);

    expect($results[0]->toArray())->toBe(['foo' => 'input1']);

    expect($results[1]->toArray())->toBe(['foo' => 'input3']);
});

test('You can add a step group as a step and all it\'s steps are invoked when the Crawler is run', function () {
    $crawler = helper_getDummyCrawler();

    $step1 = Mockery::mock(StepInterface::class);

    $step1->shouldReceive('invokeStep')->andReturn(helper_arrayToGenerator(['foo']));

    $step1->shouldReceive('addLogger');

    $step2 = Mockery::mock(StepInterface::class);

    $step2->shouldReceive('invokeStep')->andReturn(helper_arrayToGenerator(['bar']));

    $step2->shouldReceive('addLogger');

    $step3 = Mockery::mock(StepInterface::class);

    $step3->shouldReceive('invokeStep')->andReturn(helper_arrayToGenerator(['baz']));

    $step3->shouldReceive('addLogger');

    $crawler->addStep(
        Crawler::group()
            ->addStep($step1)
            ->addStep($step2)
            ->addStep($step3)
    );

    expect(true)->toBeTrue(); // So pest doesn't complain that there is no assertion.
});

test('Result objects are created when addToResult() is called and passed on through all the steps', function () {
    $crawler = helper_getDummyCrawler();

    $step = helper_getValueReturningStep('yo');

    $crawler->addStep($step->addToResult('prop1'));

    $step2 = helper_getValueReturningStep('lo');

    $crawler->addStep($step2->addToResult('prop2'));

    $step3 = helper_getValueReturningStep('foo');

    $crawler->addStep($step3);

    $step4 = helper_getValueReturningStep('bar');

    $crawler->addStep($step4);

    $crawler->input('randomInput');

    $results = helper_generatorToArray($crawler->run());

    expect($results[0])->toBeInstanceOf(Result::class);

    expect($results[0]->toArray())->toBe([
        'prop1' => 'yo',
        'prop2' => 'lo',
    ]);
});

test(
    'when calling addToResult() it creates a Result object. When the next step also adds to the result and it yields ' .
    'multiple outputs for one input, the data is added as array to the previously created Result object.',
    function () {
        $crawler = helper_getDummyCrawler();

        $step = helper_getValueReturningStep(['some' => 'thing'])->addToResult();

        $crawler->addStep($step);

        $step2 = new class () extends Step {
            protected function invoke(mixed $input): Generator
            {
                foreach (['one', 'two', 'three'] as $number) {
                    yield $number;
                }
            }
        };

        $step2->addToResult('number');

        $crawler->addStep($step2);

        $crawler->input('some input');

        $results = helper_generatorToArray($crawler->run());

        expect($results)->toHaveCount(1);

        expect($results[0])->toBeInstanceOf(Result::class);

        expect($results[0]->toArray())->toBe([
            'some' => 'thing',
            'number' => ['one', 'two', 'three'],
        ]);
    }
);

test(
    'calling addLaterToResult() doesn\'t immediately create a Result object, but adds the data to the output and ' .
    'later adds it to each Result object that is created from that output object.',
    function () {
        $crawler = helper_getDummyCrawler();

        $step = helper_getValueReturningStep(['some' => 'thing'])->addLaterToResult();

        $crawler->addStep($step);

        $step2 = new class () extends Step {
            protected function invoke(mixed $input): Generator
            {
                foreach (['one', 'two', 'three'] as $number) {
                    yield $number;
                }
            }
        };

        $step2->addToResult('number');

        $crawler->addStep($step2);

        $crawler->input('test input');

        $results = helper_generatorToArray($crawler->run());

        expect($results)->toHaveCount(3);

        expect($results[0])->toBeInstanceOf(Result::class);

        expect($results[0]->toArray())->toBe([
            'some' => 'thing',
            'number' => 'one',
        ]);

        expect($results[1]->toArray())->toBe([
            'some' => 'thing',
            'number' => 'two',
        ]);

        expect($results[2]->toArray())->toBe([
            'some' => 'thing',
            'number' => 'three',
        ]);
    }
);

test(
    'when addLaterToResult() is called, but addToResult() is not, you get the results from the step that ' .
    'addLaterToResult() was called on in the quantity of the last steps outputs.',
    function () {
        $crawler = helper_getDummyCrawler();

        $step = helper_getValueReturningStep(['some' => 'thing'])->addLaterToResult();

        $crawler->addStep($step);

        $step2 = new class () extends Step {
            protected function invoke(mixed $input): Generator
            {
                foreach (['one', 'two', 'three'] as $number) {
                    yield $number;
                }
            }
        };

        $crawler->addStep($step2);

        $crawler->input('test input');

        $results = helper_generatorToArray($crawler->run());

        expect($results)->toHaveCount(3);

        expect($results[0]->toArray())->toBe(['some' => 'thing']);

        expect($results[1]->toArray())->toBe(['some' => 'thing']);

        expect($results[2]->toArray())->toBe(['some' => 'thing']);
    }
);

test('When final steps return an array you get all values in the defined Result resource', function () {
    $crawler = helper_getDummyCrawler();

    $step1 = helper_getValueReturningStep('Donald');

    $crawler->addStep($step1->addToResult('parent'));

    $step2 = helper_getValueReturningStep(['Tick', 'Trick', 'Track']);

    $crawler->addStep($step2->addToResult('children'));

    $crawler->input('randomInput');

    $results = $crawler->run();

    expect($results->current()->toArray())->toBe([
        'parent' => 'Donald',
        'children' => ['Tick', 'Trick', 'Track'],
    ]);

    $results->next();

    expect($results->current())->toBeNull();
});

it('sends all results to the Store when there is one and still yields the results', function () {
    $store = Mockery::mock(StoreInterface::class);

    $store->shouldReceive('addLogger');

    $store->shouldReceive('store')->times(3);

    $crawler = helper_getDummyCrawler();

    $crawler->input('gogogo');

    $crawler->setStore($store);

    $step = new class () extends Step {
        protected function invoke(mixed $input): Generator
        {
            yield 'one';
            yield 'two';
            yield 'three';
        }
    };

    $crawler->addStep('number', $step);

    $results = helper_generatorToArray($crawler->run());

    expect($results)->toHaveCount(3);
});

it(
    'actually runs the crawler without the need to traverse results manually, when runAndTraverse is called',
    function () {
        $step = helper_getInputReturningStep();

        $store = Mockery::mock(StoreInterface::class);

        $store->shouldReceive('addLogger');

        $store->shouldNotReceive('store');

        $crawler = helper_getDummyCrawler()
            ->addStep($step)
            ->setStore($store)
            ->input('test');

        $crawler->run();

        $store = Mockery::mock(StoreInterface::class);

        $store->shouldReceive('store', 'addLogger')->once();

        $crawler = helper_getDummyCrawler()
            ->addStep($step)
            ->setStore($store)
            ->input('test');

        $crawler->runAndTraverse();
    }
);

it('yields only unique outputs from a step when uniqueOutput was called', function () {
    $crawler = helper_getDummyCrawler();

    $crawler->addStep(helper_getInputReturningStep()->uniqueOutputs());

    $crawler->inputs(['one', 'two', 'three', 'one', 'three', 'four', 'one', 'five', 'two']);

    $results = helper_generatorToArray($crawler->run());

    expect($results)->toHaveCount(5);
});

it(
    'cascades step outputs immediately and doesn\'t wait for the current step being called with all the inputs',
    function () {
        $step1 = new class () extends Step {
            protected function invoke(mixed $input): Generator
            {
                $this->logger?->info('step1 called');

                yield $input . ' step1-1';

                yield $input . ' step1-2';
            }
        };

        $step2 = new class () extends Step {
            protected function invoke(mixed $input): Generator
            {
                $this->logger?->info('step2 called');

                yield $input . ' step2';
            }
        };

        $store = new class () extends Store {
            public function store(Result $result): void
            {
                $this->logger?->info('Stored a result');
            }
        };

        $crawler = helper_getDummyCrawler()
            ->inputs(['input1', 'input2'])
            ->addStep('foo', $step1)
            ->addStep('bar', $step2)
            ->setStore($store);

        $crawler->runAndTraverse();

        $output = $this->getActualOutputForAssertion();

        $outputLines = explode("\n", $output);

        expect($outputLines[0])->toContain('step1 called');

        expect($outputLines[1])->toContain('step2 called');

        expect($outputLines[2])->toContain('step2 called');

        expect($outputLines[3])->toContain('Stored a result');

        expect($outputLines[4])->toContain('Stored a result');

        expect($outputLines[5])->toContain('step1 called');

        expect($outputLines[6])->toContain('step2 called');

        expect($outputLines[7])->toContain('step2 called');

        expect($outputLines[8])->toContain('Stored a result');

        expect($outputLines[9])->toContain('Stored a result');
    }
);

it('logs memory usage if you want it to', function () {
    $step1 = helper_getValueReturningStep('foo');

    $step2 = helper_getValueReturningStep('bar');

    $crawler = helper_getDummyCrawler()
        ->input('go')
        ->addStep($step1)
        ->addStep($step2)
        ->monitorMemoryUsage();

    $crawler->runAndTraverse();

    $output = $this->getActualOutputForAssertion();

    expect($output)->toContain('memory usage: ');
});

it('sends all outputs to the outputHook when defined', function () {
    $outputs = [];

    $crawler = helper_getDummyCrawler()
        ->input(1)
        ->addStep(helper_getNumberIncrementingStep())
        ->addStep(helper_getNumberIncrementingStep())
        ->outputHook(function (Output $output, int $stepIndex, StepInterface $step) use (&$outputs) {
            $outputs[$stepIndex][] = $output->get();
        });

    $crawler->runAndTraverse();

    expect($outputs)->toHaveCount(2);

    expect($outputs[0])->toHaveCount(1);

    expect($outputs[0][0])->toBe(2);

    expect($outputs[1])->toHaveCount(1);

    expect($outputs[1][0])->toBe(3);
});

test(
    'When result is not explicitly composed and last step produces array output with string keys, it uses those keys ' .
    'for the result.',
    function () {
        $crawler = helper_getDummyCrawler()
            ->input('hello')
            ->addStep(helper_getValueReturningStep(['foo' => 'bar', 'baz' => 'quz']));

        $results = helper_generatorToArray($crawler->run());

        expect($results[0]->toArray())->toBe(['foo' => 'bar', 'baz' => 'quz']);
    }
);

it('just runs the crawler and dumps all results as array when runAndDump() is called', function () {
    helper_getDummyCrawlerWithInputReturningStep()
        ->inputs([
            ['foo' => 'one', 'bar' => 'two'],
            ['baz' => 'three', 'quz' => 'four'],
        ])
        ->runAndDump();

    $actualOutput = $this->getActualOutputForAssertion();

    expect(explode('array(2)', $actualOutput))->toHaveCount(3);

    expect($actualOutput)->toContain('["foo"]=>');

    expect($actualOutput)->toContain('string(3) "one"');

    expect($actualOutput)->toContain('["bar"]=>');

    expect($actualOutput)->toContain('string(3) "two"');

    expect($actualOutput)->toContain('["baz"]=>');

    expect($actualOutput)->toContain('string(5) "three"');

    expect($actualOutput)->toContain('["quz"]=>');

    expect($actualOutput)->toContain('string(4) "four"');
});
