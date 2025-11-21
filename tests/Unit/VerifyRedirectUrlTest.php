<?php

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Zen\Obsidian\Http\Middleware\VerifyRedirectUrl;

test('middleware allows request without redirect parameter', function (): void {
  $middleware = new VerifyRedirectUrl;
  $request = Request::create('/test', 'GET');

  $response = $middleware->handle($request, fn ($req): ResponseFactory|Response => response('OK'));

  expect($response->getContent())->toBe('OK');
});

test('middleware allows request with same host redirect', function (): void {
  $middleware = new VerifyRedirectUrl;
  $request = Request::create('http://example.com/test', 'GET', [
    'redirect' => 'http://example.com/success',
  ]);

  $response = $middleware->handle($request, fn ($req): ResponseFactory|Response => response('OK'));

  expect($response->getContent())->toBe('OK');
});

test('middleware blocks request with different host redirect', function (): void {
  $middleware = new VerifyRedirectUrl;
  $request = Request::create('http://example.com/test', 'GET', [
    'redirect' => 'http://evil.com/phishing',
  ]);

  $middleware->handle($request, fn ($req): ResponseFactory|Response => response('OK'));
})->throws(AccessDeniedHttpException::class);

test('middleware allows request with relative redirect', function (): void {
  $middleware = new VerifyRedirectUrl;
  $request = Request::create('http://example.com/test', 'GET', [
    'redirect' => '/success',
  ]);

  $response = $middleware->handle($request, fn ($req): ResponseFactory|Response => response('OK'));

  expect($response->getContent())->toBe('OK');
});

test('middleware handles non-string redirect parameter', function (): void {
  $middleware = new VerifyRedirectUrl;

  // Create a request with a non-string redirect (e.g., an object that can be cast to string)
  $redirectObject = new class
  {
    public function __toString(): string
    {
      return 'http://example.com/success';
    }
  };

  $request = Request::create('http://example.com/test', 'GET');
  $request->query->set('redirect', $redirectObject);

  $response = $middleware->handle($request, fn ($req): ResponseFactory|Response => response('OK'));

  expect($response->getContent())->toBe('OK');
});
