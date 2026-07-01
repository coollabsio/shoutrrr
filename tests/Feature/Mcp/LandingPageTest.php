<?php

test('browsers visiting the mcp endpoint get a friendly landing page', function (): void {
    $this->get('/mcp', ['Accept' => 'text/html'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('mcp/landing'));
});

test('non-browser GET requests still receive the spec 405 with Allow: POST', function (): void {
    $response = $this->get('/mcp', ['Accept' => 'application/json']);

    $response->assertStatus(405);
    $response->assertHeader('Allow', 'POST');
});

test('a GET requesting the SSE stream is never served the landing page', function (): void {
    // MCP's Streamable HTTP transport uses GET + text/event-stream for the
    // server->client stream. Even though a browser Accept header also lists
    // text/html, an event-stream request must fall through to the protocol's
    // 405, not the human landing page.
    $this->get('/mcp', ['Accept' => 'text/event-stream'])
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST');

    $this->get('/mcp', ['Accept' => 'text/html, text/event-stream'])
        ->assertStatus(405);
});

test('a wildcard-only GET is not served the landing page', function (): void {
    // Bare clients (curl, health checks) sending Accept: */* are not browser
    // navigations, so they get the protocol 405 rather than HTML.
    $this->get('/mcp', ['Accept' => '*/*'])
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST');
});

test('the mcp protocol endpoint still requires authentication on POST', function (): void {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ], ['Accept' => 'application/json, text/event-stream']);

    $response->assertUnauthorized();
});
