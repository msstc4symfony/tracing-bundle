# Tracing symfony bundle

This Symfony bundle is responsible for receiving, storing, and transmitting a runtime identifier, thereby enabling request tracing from the moment it is initiated by a user or an external system, throughout the entire structure of the application's services.

The bundle introduces a concepts:
* `request ID` — an identifier of the request;
* `request from` — indicating who sent the request to the current service;
* `runtime ID` — an identifier of the current runtime in the current service.

The bundle accepts the `request ID` and `request from` from all incoming HTTP requests and Symfony Messenger messages.

The bundle forwards the `request ID` and `request from` in all outgoing HTTP requests made using the Symfony HTTP client or Guzzle, as well as in all outgoing Symfony Messenger messages.

## Usage

### Get current `request id` and `request from`

```php
final class Service
{
    public function __construct(
        private RequestIdServiceInterface $storage,
    ) {
    }

    public function doSomething(): void
    {
        $runtimeId = $this->storage->getRuntimeId();
        $requestId = $this->storage->getRequestId();
        $requestFrom = $this->storage->getRequestFrom();
    }
}
```

## Local development

Check code:
```shell
make check
```

Fix code:
```shell
make fix
```









