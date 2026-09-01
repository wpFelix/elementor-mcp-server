<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- OAuth 2.1 protocol endpoint called by external MCP clients; WordPress nonces cannot exist in this flow. Input is validated against the RFC grammar and rejected with protocol errors, and redirects go to client-registered callback URLs as the spec requires.

if (!defined('ABSPATH')) {
    exit();
}

function elementor_mcp_is_sensitive_rest_route(string $route): bool
{
    return (
        $route === '/mcp/elementor-mcp'
        || str_starts_with($route, '/mcp/elementor-mcp/')
        || $route === '/mcp/elementor-mcp-oauth'
        || str_starts_with($route, '/mcp/elementor-mcp-oauth/')
        || $route === '/mcp/mcp-adapter-default-server'
        || str_starts_with($route, '/mcp/mcp-adapter-default-server/')
        || str_starts_with($route, '/elementor-mcp/v1/')
    );
}

/** @return list<string> */
function elementor_mcp_allowed_request_hosts(): array
{
    $hosts = [];
    foreach ([home_url('/'), site_url('/')] as $url) {
        // @mago-expect analysis:mixed-assignment -- wp_parse_url returns string|false|null for this component.
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $hosts[] = strtolower(rtrim(string: $host, characters: '.'));
        }
    }
    /** @var mixed $filtered */
    $filtered = apply_filters('elementor_mcp_allowed_request_hosts', array_values(array_unique($hosts)));
    if (!is_array($filtered)) {
        return array_values(array_unique($hosts));
    }
    return array_values(array_unique(array_filter(array_map(static fn(mixed $host): string => is_string($host)
        ? strtolower(rtrim(string: trim($host), characters: '.'))
        : '', $filtered))));
}

function elementor_mcp_normalize_request_host(string $host_header): string
{
    // @mago-expect analysis:mixed-assignment -- wp_parse_url returns string|false|null for this component.
    $parsed = wp_parse_url('http://' . trim($host_header), PHP_URL_HOST);
    return is_string($parsed) ? strtolower(rtrim(string: $parsed, characters: '.')) : '';
}

function elementor_mcp_guard_rest_host(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
{
    if ($result !== null || !elementor_mcp_is_sensitive_rest_route($request->get_route())) {
        return $result;
    }
    $host_header = $_SERVER['HTTP_HOST'] ?? '';
    if ($host_header === '') {
        return new WP_Error('elementor_mcp_host_missing', __('The request Host header is required.', domain: 'elementor-mcp'), [
            'status' => 400,
        ]);
    }
    $host = elementor_mcp_normalize_request_host($host_header);
    if ($host === '' || !in_array($host, elementor_mcp_allowed_request_hosts(), strict: true)) {
        return new WP_Error(
            'elementor_mcp_host_mismatch',
            __('The request Host does not match this WordPress installation.', domain: 'elementor-mcp'),
            ['status' => 421],
        );
    }
    return $result;
}

function elementor_mcp_harden_rest_response(
    WP_HTTP_Response $response,
    WP_REST_Server $server,
    WP_REST_Request $request,
): WP_HTTP_Response {
    if (!elementor_mcp_is_sensitive_rest_route($request->get_route())) {
        return $response;
    }
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
    $response->header('Pragma', 'no-cache');
    $response->header('Expires', '0');
    $response->header('X-Content-Type-Options', 'nosniff');
    $response->header('Referrer-Policy', 'no-referrer');
    return $response;
}
