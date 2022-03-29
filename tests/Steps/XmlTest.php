<?php

namespace tests\Steps;

use Crwlr\Crawler\Input;
use Crwlr\Crawler\Steps\Dom;
use Crwlr\Crawler\Steps\Xml;
use function tests\helper_generatorToArray;

function helper_getXmlContent(string $fileName): string
{
    $content = file_get_contents(__DIR__ . '/_Files/Xml/' . $fileName);

    if ($content === false) {
        return '';
    }

    return $content;
}

it('extracts data from an XML document with XPath queries per default', function () {
    $xml = helper_getXmlContent('bookstore.xml');

    $output = helper_generatorToArray(
        Xml::each('bookstore/book')->extract([
            'title' => '//title',
            'author' => '//author',
            'year' => '//year',
        ])->invokeStep(new Input($xml))
    );

    expect($output)->toHaveCount(4);

    expect($output[0]->get())->toBe(
        ['title' => 'Everyday Italian', 'author' => 'Giada De Laurentiis', 'year' => '2005']
    );

    expect($output[1]->get())->toBe(['title' => 'Harry Potter', 'author' => 'J K. Rowling', 'year' => '2005']);

    expect($output[2]->get())->toBe(
        [
            'title' => 'XQuery Kick Start',
            'author' => ['James McGovern', 'Per Bothner', 'Kurt Cagle', 'James Linn', 'Vaidyanathan Nagarajan'],
            'year' => '2003'
        ]
    );

    expect($output[3]->get())->toBe(['title' => 'Learning XML', 'author' => 'Erik T. Ray', 'year' => '2003']);
});

it('can also extract data using CSS selectors', function () {
    $xml = helper_getXmlContent('bookstore.xml');

    $output = helper_generatorToArray(
        Xml::each(Dom::cssSelector('bookstore book'))->extract([
            'title' => Dom::cssSelector('title'),
            'author' => Dom::cssSelector('author'),
            'year' => Dom::cssSelector('year'),
        ])->invokeStep(new Input($xml))
    );

    expect($output)->toHaveCount(4);

    expect($output[2]->get())->toBe(
        [
            'title' => 'XQuery Kick Start',
            'author' => ['James McGovern', 'Per Bothner', 'Kurt Cagle', 'James Linn', 'Vaidyanathan Nagarajan'],
            'year' => '2003'
        ]
    );
});

it('returns only one (compound) output when the root method is used', function () {
    $xml = helper_getXmlContent('bookstore.xml');

    $output = helper_generatorToArray(
        Xml::root()->extract([
            'title' => '//title',
            'author' => '//author',
            'year' => '//year',
        ])->invokeStep(new Input($xml))
    );

    expect($output)->toHaveCount(1);

    expect($output[0]->get()['title'])->toBe(['Everyday Italian', 'Harry Potter', 'XQuery Kick Start', 'Learning XML']);
});

it('extracts the data of the first matching element when the first method is used', function () {
    $xml = helper_getXmlContent('bookstore.xml');

    $output = helper_generatorToArray(
        Xml::first('bookstore/book')->extract([
            'title' => '//title',
            'author' => '//author',
            'year' => '//year',
        ])->invokeStep(new Input($xml))
    );

    expect($output)->toHaveCount(1);

    expect($output[0]->get())->toBe(
        ['title' => 'Everyday Italian', 'author' => 'Giada De Laurentiis', 'year' => '2005']
    );
});

it('extracts the data of the last matching element when the last method is used', function () {
    $xml = helper_getXmlContent('bookstore.xml');

    $output = helper_generatorToArray(
        Xml::last('bookstore/book')->extract([
            'title' => '//title',
            'author' => '//author',
            'year' => '//year',
        ])->invokeStep(new Input($xml))
    );

    expect($output)->toHaveCount(1);

    expect($output[0]->get())->toBe(['title' => 'Learning XML', 'author' => 'Erik T. Ray', 'year' => '2003']);
});
