<?php

use Crumbls\Importer\Pipeline\PipelineContext;

it('can set and get values', function () {
    $context = new PipelineContext();
    
    $context->set('test_key', 'test_value');
    
    expect($context->get('test_key'))->toBe('test_value');
});

it('returns default value when key does not exist', function () {
    $context = new PipelineContext();
    
    expect($context->get('nonexistent', 'default'))->toBe('default');
});

it('can check if key exists', function () {
    $context = new PipelineContext();
    
    $context->set('existing_key', 'value');
    
    expect($context->has('existing_key'))->toBeTrue();
    expect($context->has('nonexistent_key'))->toBeFalse();
});

it('can merge data', function () {
    $context = new PipelineContext();
    
    $context->set('key1', 'value1');
    $context->merge(['key2' => 'value2', 'key3' => 'value3']);
    
    expect($context->get('key1'))->toBe('value1');
    expect($context->get('key2'))->toBe('value2');
    expect($context->get('key3'))->toBe('value3');
});

it('can be converted to array', function () {
    $context = new PipelineContext();
    
    $context->set('key1', 'value1');
    $context->set('key2', 'value2');
    
    $array = $context->toArray();
    
    expect($array)->toBe(['key1' => 'value1', 'key2' => 'value2']);
});

it('can be created from array', function () {
    $data = ['key1' => 'value1', 'key2' => 'value2'];
    
    $context = PipelineContext::fromArray($data);
    
    expect($context->get('key1'))->toBe('value1');
    expect($context->get('key2'))->toBe('value2');
});

it('can forget keys', function () {
    $context = new PipelineContext();
    
    $context->set('key1', 'value1');
    $context->set('key2', 'value2');
    
    $context->forget('key1');
    
    expect($context->has('key1'))->toBeFalse();
    expect($context->has('key2'))->toBeTrue();
});

it('supports method chaining', function () {
    $context = new PipelineContext();
    
    $result = $context
        ->set('key1', 'value1')
        ->set('key2', 'value2')
        ->forget('key1');
    
    expect($result)->toBe($context);
    expect($context->has('key1'))->toBeFalse();
    expect($context->has('key2'))->toBeTrue();
});
