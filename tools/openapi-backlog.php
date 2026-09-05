<?php

declare(strict_types=1);

/**
 * Endpoints that shipped before the contract did.
 *
 * Not an exemption list — a ratchet. `gate:openapi` fails if anything here is
 * also described in openapi.json, so an entry cannot survive being
 * documented, and it fails if a served route is neither described nor listed,
 * so the debt cannot grow while it is being paid down.
 *
 * The only correct edit to this file is a deletion.
 *
 * @return list<string>
 */

return [



    // /api/v1/notifications
    'GET /api/v1/notifications',
    'GET /api/v1/notifications/unread-count',
    'POST /api/v1/notifications/read-all',
    'GET /api/v1/notifications/preferences',
    'PUT /api/v1/notifications/preferences',
    'GET /api/v1/notifications/consents',
    'POST /api/v1/notifications/consents',
    'DELETE /api/v1/notifications/consents/{consentId}',
    'POST /api/v1/notifications/{notificationId}/read',
    'GET /api/v1/notifications/{notificationId}/deliveries',


    // /api/v1/webhooks
    'POST /api/v1/webhooks/payments/{provider}',
    'POST /api/v1/webhooks/einvoice/{provider}',


    // /api/v1/projects
    'GET /api/v1/projects',
    'POST /api/v1/projects',
    'GET /api/v1/projects/{projectId}',
    'PATCH /api/v1/projects/{projectId}',
    'DELETE /api/v1/projects/{projectId}',
    'GET /api/v1/projects/{projectId}/versions',
    'POST /api/v1/projects/{projectId}/versions',
    'GET /api/v1/projects/{projectId}/versions/{versionId}',
    'POST /api/v1/projects/{projectId}/duplicate',
    'POST /api/v1/projects/{projectId}/restore',
    'GET /api/v1/projects/{projectId}/assets',
    'POST /api/v1/projects/{projectId}/assets',
    'POST /api/v1/projects/{projectId}/exports',

    // /api/v1/assets
    'GET /api/v1/assets/{assetId}',
    'DELETE /api/v1/assets/{assetId}',
    'POST /api/v1/assets/{assetId}/link',

    // /api/v1/downloads
    'GET /api/v1/downloads/{assetId}/content',

    // /api/v1/tenants
    'GET /api/v1/tenants/current',
    'PATCH /api/v1/tenants/current',
    'GET /api/v1/tenants/current/usage',
    'GET /api/v1/tenants/current/members',
    'POST /api/v1/tenants/current/members',
    'PATCH /api/v1/tenants/current/members/{userId}',
    'DELETE /api/v1/tenants/current/members/{userId}',

    // /api/v1/staff
    'GET /api/v1/staff/me',
    'GET /api/v1/staff/tenants',
    'GET /api/v1/staff/tenants/{tenantId}',
    'GET /api/v1/staff/access-log',
    'GET /api/v1/staff/conversations',
    'GET /api/v1/staff/conversations/{conversationId}',
    'POST /api/v1/staff/conversations/{conversationId}/messages',
    'POST /api/v1/staff/conversations/{conversationId}/close',

    // /api/v1/admin
    'GET /api/v1/admin/audit',
    'GET /api/v1/admin/metrics',
    'GET /api/v1/admin/queue',
    'POST /api/v1/admin/erasures',

    // /api/v1/jobs
    'GET /api/v1/jobs',
    'POST /api/v1/jobs',
    'GET /api/v1/jobs/{jobId}',
    'POST /api/v1/jobs/{jobId}/cancel',

    // /api/v1/conversations
    'GET /api/v1/conversations',
    'POST /api/v1/conversations',
    'GET /api/v1/conversations/{conversationId}',
    'POST /api/v1/conversations/{conversationId}/close',
    'GET /api/v1/conversations/{conversationId}/messages',
    'POST /api/v1/conversations/{conversationId}/messages',
    'DELETE /api/v1/conversations/{conversationId}/messages/{messageId}',
    'POST /api/v1/conversations/{conversationId}/read',
    'POST /api/v1/conversations/{conversationId}/participants',
    'DELETE /api/v1/conversations/{conversationId}/participants/{userId}',
];
