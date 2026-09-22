import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-bundle.js';
import 'swagger-ui-dist/swagger-ui.css';

/* ------------------------------------------------------------------ */
/* Krotze API reference — Swagger UI over /api/openapi.json           */
/*                                                                    */
/* Bundled rather than loaded from a CDN: the site makes no third-     */
/* party requests, and the docs should keep working on an air-gapped   */
/* or self-hosted install.                                            */
/* ------------------------------------------------------------------ */

SwaggerUIBundle({
    url: '/api/openapi.json',
    dom_id: '#swagger',
    deepLinking: true,
    docExpansion: 'list',
    defaultModelsExpandDepth: 0,
    persistAuthorization: true,
    tryItOutEnabled: true,
    // "Try it out" can only really work for the unsigned endpoints — the signed
    // ones need an HMAC over the request, which the browser cannot compute from
    // a header field the user types in. Say so rather than let people wonder.
    onComplete: () => {
        const note = document.getElementById('signing-note');
        if (note) note.hidden = false;
    },
});
