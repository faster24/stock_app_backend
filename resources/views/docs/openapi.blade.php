<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>API Docs</title>
        <style>
            :root {
                color-scheme: light;
                --bg: #f4efe5;
                --panel: #fffaf2;
                --ink: #1a2233;
                --line: #d8c9a8;
                --accent: #b25a2b;
                --accent-soft: #f1dbc5;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                background:
                    radial-gradient(circle at top left, rgba(178, 90, 43, 0.18), transparent 28%),
                    radial-gradient(circle at bottom right, rgba(26, 34, 51, 0.12), transparent 24%),
                    linear-gradient(180deg, #f8f3ea 0%, var(--bg) 100%);
                color: var(--ink);
                font-family: Georgia, "Times New Roman", serif;
            }

            .shell {
                padding: 24px;
            }

            .hero {
                max-width: 1120px;
                margin: 0 auto 24px;
                padding: 24px;
                border: 1px solid var(--line);
                border-radius: 24px;
                background: rgba(255, 250, 242, 0.82);
                backdrop-filter: blur(10px);
                box-shadow: 0 24px 80px rgba(60, 42, 18, 0.08);
            }

            .eyebrow {
                margin: 0 0 12px;
                color: var(--accent);
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.18em;
                text-transform: uppercase;
            }

            h1 {
                margin: 0;
                font-size: clamp(2rem, 4vw, 4rem);
                line-height: 0.92;
            }

            .lede {
                max-width: 760px;
                margin: 16px 0 0;
                font-size: 1.05rem;
                line-height: 1.6;
            }

            .meta {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
                margin-top: 18px;
            }

            .pill,
            .meta a {
                display: inline-flex;
                align-items: center;
                min-height: 38px;
                padding: 0 14px;
                border: 1px solid var(--line);
                border-radius: 999px;
                background: var(--panel);
                color: var(--ink);
                font-size: 0.95rem;
                text-decoration: none;
            }

            .meta a {
                background: var(--accent-soft);
                border-color: rgba(178, 90, 43, 0.35);
            }

            .docs-frame {
                max-width: 1120px;
                margin: 0 auto;
                overflow: hidden;
                border: 1px solid var(--line);
                border-radius: 24px;
                background: var(--panel);
                box-shadow: 0 24px 80px rgba(60, 42, 18, 0.08);
            }

            redoc {
                display: block;
            }
        </style>
    </head>
    <body>
        <main class="shell">
            <section class="hero">
                <p class="eyebrow">OpenAPI Reference</p>
                <h1>Stock App Backend API</h1>
                <p class="lede">
                    Interactive reference for the current API contract, rendered directly from the repository spec.
                </p>
                <div class="meta">
                    <span class="pill">Spec source: `docs/openapi.yaml`</span>
                    <a href="{{ $specUrl }}">Raw YAML</a>
                </div>
            </section>

            <section class="docs-frame">
                <redoc spec-url="{{ $specUrl }}"></redoc>
            </section>
        </main>

        <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
    </body>
</html>
