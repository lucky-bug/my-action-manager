<?php

declare(strict_types = 1);

use My\Action;
use My\ActionManager;
use My\ActionRegistry;
use My\Preformatted;

$completion_callback = new class(ActionManager::instance()->getActionRegistry()) {
  private const INDEX_ACTION_NAME = 0;

  private ActionRegistry $action_registry;

  public function __construct(ActionRegistry $action_registry) {
    $this->action_registry = $action_registry;
  }

  public function __invoke($input, $index): array {
    if ($index === self::INDEX_ACTION_NAME) {
      return array_filter(
        array_map(
          fn(Action $action) => $action->getName(),
          $this->action_registry->resolveAll()
        ),
        fn(string $name) => preg_match('#^' . $input . '#', $name) === 1
      );
    }

    return [];
  }
};

readline_completion_function($completion_callback);

$action_manager  = ActionManager::instance();
$action_registry = $action_manager->getActionRegistry();

$name   = trim(readline('Action: '));
$action = $action_registry->resolve($name);

if ($action === null) {
  echo 'Action not found';
  exit(1);
}

$arguments = [];

foreach ($action->getReflection()->getParameters() as $parameter) {
  $type  = $parameter->getType()->getName();
  $value = null;

  while ($value === null) {
    $prompt = $parameter->getName();
    $input  = trim(readline($prompt . ': '));

    if ($input === '') {
      $value = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
    } elseif ($type === 'bool') {
      $value = boolval($input);
    } elseif ($type === 'int') {
      $value = intval($input);
    } elseif ($type === 'float') {
      $value = floatval($input);
    } else {
      $value = $input;
    }
  }

  $arguments[] = $value;
}

$preformatted_list = $action_manager->handle(
  $action,
  $arguments
);

if (count($preformatted_list) === 0) {
  $preformatted_list[] = new Preformatted('Status', 'Done');
}

foreach ($preformatted_list as $preformatted) {
  echo '---' . PHP_EOL;
  echo $preformatted->getTitle() . PHP_EOL;
  echo $preformatted->getBody() . PHP_EOL;
  echo '---' . PHP_EOL;
}
