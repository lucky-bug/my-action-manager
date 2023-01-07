<?php

declare(strict_types = 1);

namespace My;

use Closure;
use ReflectionFunction;
use Throwable;

final class Action {
  private Closure $closure;
  private ReflectionFunction $reflection;
  private bool $risky;
  private bool $justValue;
  private bool $anonymous;
  private ?string $name;

  public function __construct(
    callable $callable,
    bool $risky = true
  ) {
    $this->closure    = Closure::fromCallable($callable);
    $this->reflection = new ReflectionFunction($this->closure);
    $this->risky      = $risky;
    $this->justValue  = false;
    $this->anonymous  = true;
    $this->name       = null;
  }

  public function getClosure(): Closure {
    return $this->closure;
  }

  public function getReflection(): ReflectionFunction {
    return $this->reflection;
  }

  public function isAnonymous(): bool {
    return $this->anonymous;
  }

  public function setAnonymous(bool $anonymous): Action {
    $this->anonymous = $anonymous;

    return $this;
  }

  public function hasName(): bool {
    return $this->name !== null;
  }

  public function getName(): ?string {
    return $this->name;
  }

  public function setName(string $name): Action {
    $this->name = $name;

    return $this;
  }

  public function isRisky(): bool {
    return $this->risky;
  }

  public function isJustValue(): bool {
    return $this->justValue;
  }

  public function setJustValue(bool $justValue): Action {
    $this->justValue = $justValue;

    return $this;
  }
}

final class ActionRegistry {
  /**
   * @var Action[]
   */
  private array $actions;

  public function __construct() {
    $this->actions = [];
  }

  public function register(Action $action): self {
    $this->actions[$action->getName()] = $action;

    return $this;
  }

  public function resolve(string $name): ?Action {
    return $this->actions[$name] ?? null;
  }

  public function resolveLast(): ?Action {
    $key = array_key_last($this->actions);

    return $key === null ? null : $this->actions[$key];
  }

  /**
   * @return Action[]
   */
  public function resolveAll(): array {
    return $this->actions;
  }
}

final class ValidationStatus {
  private const CODE_VALID   = 0;
  private const CODE_INVALID = 1;

  private string $message;
  private int $code;

  public function __construct(
    string $message,
    int $code
  ) {
    $this->message = $message;
    $this->code    = $code;
  }

  public static function valid(): ValidationStatus {
    return new ValidationStatus(
      'OK',
      self::CODE_VALID
    );
  }

  public static function invalid(string $message): ValidationStatus {
    return new ValidationStatus(
      $message,
      self::CODE_INVALID
    );
  }

  public function getMessage(): string {
    return $this->message;
  }

  public function getCode(): int {
    return $this->code;
  }

  public function isValid(): bool {
    return $this->code === self::CODE_VALID;
  }

  public function isInvalid(): bool {
    return !$this->isValid();
  }
}

interface ActionValidator {
  public function validate(Action $action): ValidationStatus;
}

final class Preformatted {
  private string $title;
  private string $body;
  private string $language;

  public function __construct(
    string $title,
    string $body,
    string $language = 'plaintext'
  ) {
    $this->title    = $title;
    $this->body     = $body;
    $this->language = $language;
  }

  public function getTitle(): string {
    return $this->title;
  }

  public function getBody(): string {
    return $this->body;
  }

  public function getLanguage(): string {
    return $this->language;
  }
}

final class ActionManager {
  private static ActionManager $instance;

  private ActionRegistry $action_registry;

  private function __construct(
    ActionRegistry $action_registry
  ) {
    $this->action_registry = $action_registry;
  }

  public static function instance(): ActionManager {
    if (!isset(self::$instance)) {
      self::$instance = new ActionManager(
        new ActionRegistry()
      );
    }

    return self::$instance;
  }

  public function run(): void {
    if (PHP_SAPI === 'cli') {
      include 'cli.php';
    } elseif (PHP_SAPI === 'apache2handler') {
      include 'web.php';
    }
  }

  public function load(array $actions): void {
    foreach ($actions as $key => $value) {
      if ($value instanceof Action) {
        $action = $value;
      } elseif (is_callable($value)) {
        $action = new Action($value);
      } else {
        $action = new Action(fn() => $value, false);
        $action->setJustValue(true);
      }

      if ($action->hasName()) {
        $action->setAnonymous(false);
      } else {
        if (is_string($key)) {
          $action->setAnonymous(false);
          $name = $key;
        } else {
          $name = 'anonymous-' . $key;
        }

        $action->setName($name);
      }

      $this->action_registry->register($action);
    }
  }

  /**
   * @return Preformatted[]
   */
  public function handle(Action $action, array $arguments = []): array {
    /** @var Preformatted[] $preformatted_list */
    $preformatted_list = [];

    ob_start();

    try {
      $throwable = null;
      $value     = call_user_func_array($action->getClosure(), $arguments);

      if ($value) {
        $preformatted_list[] = new Preformatted(
          'Value',
          var_export($value, true),
          'php'
        );
      }
    } catch (Throwable $t) {
      $throwable = $t;
    }

    $output = ob_get_clean();

    if ($output) {
      $preformatted_list[] = new Preformatted(
        'Output',
        $output
      );
    }

    if ($throwable) {
      $preformatted_list[] = new Preformatted(
        'Throwable',
        var_export($throwable, true),
        'php'
      );
    }

    return $preformatted_list;
  }

  public function getActionRegistry(): ActionRegistry {
    return $this->action_registry;
  }
}
