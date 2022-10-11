<?php

namespace Lifo\Daemon;

use Exception;

/**
 * A simple Promise implementation. The heart of this class was inspired by fruux/sabre-event on github and simplified.
 */
class Promise
{
    const PENDING   = 0;
    const FULFILLED = 1;
    const REJECTED  = 2;

    private int $state = self::PENDING;

    /**
     * A list of subscriber callbacks
     */
    private array $subscribers = [];

    /**
     * The result of the promise
     *
     * @var mixed|null
     */
    private $value = null;

    /**
     * Creates the promise, optionally calling the executor as $executor($fulfill, $reject)
     *
     * @param callable|null $executor
     *
     * @throws Exception
     */
    public function __construct(callable $executor = null)
    {
        if ($executor) {
            if (!is_callable($executor)) {
                throw new Exception("Invalid callable for Promise construction");
            }
            call_user_func($executor, [$this, 'fulfill'], [$this, 'reject']);
        }
    }

    /**
     * Set callbacks for when the promise has been fulfilled or rejected.
     *
     * This method returns a new promise, which can be used for chaining.
     * If either the onFulfilled or onRejected callback is called, you may return a result from this callback.
     *
     * If the result of this callback is yet another promise, the result of _that_ promise will be used to set the
     * result of the returned promise.
     *
     * If either of the callbacks return any other value, the returned promise is automatically fulfilled with that
     * value.
     *
     * If either of the callbacks throw an exception, the returned promise will be rejected and the exception will be
     * passed back.
     *
     * @param callable|null $onFulfilled
     * @param callable|null $onRejected
     *
     * @return Promise
     * @throws Exception
     */
    public function then(callable $onFulfilled = null, callable $onRejected = null): self
    {
        $promise = new self;

        if ($onFulfilled && !is_callable($onFulfilled)) {
            throw new Exception("Invalid callable for \$onFulfilled");
        }
        if ($onRejected && !is_callable($onRejected)) {
            throw new Exception("Invalid callable for \$onRejected");
        }

        switch ($this->state) {
            case self::PENDING:
                $this->subscribers[] = [$promise, $onFulfilled, $onRejected];
                break;
            case self::FULFILLED:
                $this->doCallback($promise, $onFulfilled);
                break;
            case self::REJECTED:
                $this->doCallback($promise, $onRejected);
                break;
        }

        return $promise;
    }

    /**
     * Add a callback for when this promise is rejected.
     * Shortcut for {@link then}(null, callable).
     *
     * @param callable $onRejected
     *
     * @return Promise
     */
    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Add a callback to always be called no matter of its state
     * Shortcut for {@link then}(callable, callable).
     *
     * @param callable $callback
     *
     * @return Promise
     */
    public function always(callable $callback): self
    {
        return $this->then($callback, $callback);
    }

    /**
     * Marks this promise as fulfilled and sets its return value.
     *
     * @param mixed $value
     * @return void
     * @throws Exception
     */
    public function fulfill($value = null)
    {
        if ($this->state !== self::PENDING) {
            throw new Exception('Promise already resolved');
        }
        $this->state = self::FULFILLED;
        $this->value = $value;
        foreach ($this->subscribers as $subscriber) {
            $this->doCallback($subscriber[0], $subscriber[1]);
        }
    }

    /**
     * Marks this promise as rejected, and set it's rejection reason.
     *
     * @param mixed $reason
     * @return void
     * @throws Exception
     */
    public function reject($reason)
    {
        if ($this->state !== self::PENDING) {
            throw new Exception('Promise already resolved');
        }
        $this->state = self::REJECTED;
        $this->value = $reason;
        foreach ($this->subscribers as $subscriber) {
            $this->doCallback($subscriber[0], $subscriber[2]);
        }

    }

    /**
     * Returns true if the current state matches
     *
     * @param int $state
     *
     * @return bool
     */
    public function isState(int $state): bool
    {
        return $this->state == $state;
    }

    /**
     * Return true if the promise is empty (has no callbacks registered). This has very limited value since a callback
     * can be registered way after a promise is fulfilled. However, I have a use-case within the {@link Mediator} to
     * allow a user to use the promise or rely on an old-school ON_RETURN event.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->subscribers);
    }

    /**
     * Call the callback for fulfillment or rejection of the promise.
     *
     * This method makes sure that the result of these callbacks are handled correctly, and any chained promises are
     * also correctly fulfilled or rejected.
     *
     * @param Promise       $promise
     * @param callable|null $callback
     *
     * @return void
     */
    private function doCallback(Promise $promise, callable $callback = null)
    {
        if (is_callable($callback)) {
            try {
                $result = call_user_func($callback, $this->value);
                if ($result instanceof self) {
                    // If the callback (onRejected or onFulfilled) returned a promise, we only fulfill or reject the
                    // chained promise once that promise has also been resolved.
                    $result->then([$promise, 'fulfill'], [$promise, 'reject']);
                } else {
                    // If the callback returned any other value, we immediately fulfill the chained promise.
                    $promise->fulfill($result);
                }
            } catch (Exception $e) {
                // If the event handler threw an exception, we need to make sure that the chained promise is rejected
                // as well.
                $promise->reject($e);
            }
        } else {
            if ($this->state === self::FULFILLED) {
                $promise->fulfill($this->value);
            } else {
                $promise->reject($this->value);
            }
        }
    }
}