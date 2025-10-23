.PHONY: default install tests

default: install
install:
	docker compose run --rm cli composer install

tests: install
	docker compose run --rm cli composer tests

clean:
	rm -rf vendor var composer.lock .php-cs-fixer.cache .phpunit.result.cache
