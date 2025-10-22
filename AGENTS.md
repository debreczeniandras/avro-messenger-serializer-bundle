# Testing Strategy

- Use `docker compose run --rm cli` to execute PHP tooling inside the Composer container.
- Run the test suite with `docker compose run --rm cli ./vendor/bin/simple-phpunit`.
- Keep tests focused on bundle wiring, schema loading, metadata resolution, and serializer behaviour.
