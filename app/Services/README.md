# Service Layer Guardrails

- Place business logic in `app/Services` and keep controllers thin.
- Inject concrete service classes into controllers via constructor injection.
- Prefer single-purpose methods with explicit inputs/outputs.
- Do not add repository classes or repository interfaces.
- Keep service dependencies explicit through constructor arguments.

Example controller injection pattern:

```php
public function __construct(private \App\Services\Auth\AuthService $authService)
{
}
```
