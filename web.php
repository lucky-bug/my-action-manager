<?php

declare(strict_types = 1);

use My\Action;
use My\ActionValidator;
use My\ActionManager;
use My\Preformatted;
use My\ValidationStatus;

$action_manager = ActionManager::instance();

$confirmation_helper = new class implements ActionValidator {
  private const VALID_PARAMETER_TYPES = [
    'string',
    'int',
    'float',
    'bool',
  ];

  public function validate(Action $action): ValidationStatus {
    foreach ($action->getReflection()->getParameters() as $parameter) {
      $parameter_type = $parameter->getType();
      $parameter_name = $parameter->getName();

      if ($parameter_type === null) {
        return ValidationStatus::invalid(
          "Parameter type declaration is missing: $parameter_name"
        );
      }

      $type_name = $parameter_type->getName();

      if (!in_array(
        $type_name,
        self::VALID_PARAMETER_TYPES,
        true
      )) {
        return ValidationStatus::invalid(
          "Invalid parameter type: $type_name"
        );
      }
    }

    return ValidationStatus::valid();
  }

  public function resolveCodeInputName(): string {
    return 'action-code';
  }

  public function resolveConfirmationInputName(): string {
    return 'action-confirmation';
  }

  public function resolveCode(Action $action): string {
    static $action_codes = [];

    if (!isset($action_codes[$action->getName()])) {
      $action_codes[$action->getName()] = $this->generateCode();
    }

    return $action_codes[$action->getName()];
  }

  public function isConfirmed(Action $action): bool {
    return !$action->isRisky() || $_REQUEST[$this->resolveConfirmationInputName()] === $_REQUEST[$this->resolveCodeInputName()];
  }

  private function generateCode(): string {
    $characters = range('a', 'z');
    $password   = '';

    foreach (range(1, 4) as $ignored) {
      $password .= $characters[rand(0, count($characters) - 1)];
    }

    return $password;
  }
};

$flags_helper = new class($confirmation_helper) {
  public const KEY_ICON    = 'icon';
  public const KEY_CLASSES = 'classes';

  private ActionValidator $action_validator;

  public function __construct(ActionValidator $action_validator) {
    $this->action_validator = $action_validator;
  }

  public function hasAny(Action $action): bool {
    return count($this->resolveAll($action)) > 0;
  }

  public function resolveAll(Action $action): array {
    $flags = [];

    if ($action->isAnonymous()) {
      $flags[] = [
        self::KEY_ICON => 'text',
      ];
    }

    if ($action->isJustValue()) {
      $flags[] = [
        self::KEY_ICON    => 'shapes',
        self::KEY_CLASSES => 'text-blue-500',
      ];
    }

    if ($action->isRisky()) {
      $flags[] = [
        self::KEY_ICON    => 'warning',
        self::KEY_CLASSES => 'text-yellow-500',
      ];
    }

    if ($this->action_validator->validate($action)->isInvalid()) {
      $flags[] = [
        self::KEY_ICON    => 'alert-circle',
        self::KEY_CLASSES => 'text-red-500',
      ];
    }

    return $flags;
  }
};

$parameters_helper = new class {
  public function hasAny(Action $action): bool {
    return count($this->resolveAll($action)) > 0;
  }

  /**
   * @return ReflectionParameter[]
   */
  public function resolveAll(Action $action): array {
    return $action->getReflection()->getParameters();
  }

  public function resolveTypeName(ReflectionParameter $parameter): string {
    return $parameter->getType()->getName();
  }

  public function resolveInputName(ReflectionParameter $parameter): string {
    return 'action-param-' . $parameter->getName();
  }

  /**
   * @return mixed|null
   */
  public function resolveDefaultValue(ReflectionParameter $parameter) {
    try {
      return $parameter->getDefaultValue();
    } catch (ReflectionException $e) {
      return null;
    }
  }

  public function resolveArguments(Action $action): array {
    $arguments = [];

    foreach ($this->resolveAll($action) as $parameter) {
      $type  = $this->resolveTypeName($parameter);
      $input = $_REQUEST[$this->resolveInputName($parameter)] ?? null;

      if ($type === 'bool') {
        $value = boolval($input);
      } elseif ($type === 'int') {
        $value = intval($input);
      } elseif ($type === 'float') {
        $value = floatval($input);
      } else {
        $value = $input;
      }

      $arguments[] = $value;
    }

    return $arguments;
  }
};

$dark_mode_helper = new class {
  private const ICON_SUNNY = 'sunny';
  private const ICON_MOON  = 'moon';

  public function resolveCookieKey(): string {
    return 'dark-mode';
  }

  public function isDarkModeEnabled(): bool {
    return boolval($_COOKIE[$this->resolveCookieKey()]);
  }

  public function resolveIcon(): string {
    return $this->isDarkModeEnabled() ? self::ICON_SUNNY : self::ICON_MOON;
  }
};

$action_helper = new class {
  public function resolveLocation(Action $action): string {
    $filename = $action->getReflection()->getFileName();

    preg_match('#^' . getcwd() . '(.*)$#', $filename, $matches);

    return sprintf(
      "%s:%s",
      $matches[1] ?? $filename,
      $action->getReflection()->getStartLine()
    );
  }

  public function resolveFormId(Action $action): string {
    return 'action-form-' . $action->getName();
  }

  public function resolveActionInputName(): string {
    return 'action-name';
  }

  public function resolveExecutedActionName(): string {
    return $_REQUEST[$this->resolveActionInputName()] ?? '';
  }
};

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/** @var Preformatted[] $preformatted_list */
$preformatted_list = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name   = $action_helper->resolveExecutedActionName();
  $action = $action_manager->getActionRegistry()->resolve($name);

  if ($action && $confirmation_helper->isConfirmed($action)) {
    $preformatted_list = $action_manager->handle(
      $action,
      $parameters_helper->resolveArguments($action)
    );

    if (count($preformatted_list) === 0) {
      $preformatted_list[] = new Preformatted('Status', 'Done');
    }
  } elseif ($action === null) {
    $preformatted_list[] = new Preformatted(
      'Validation',
      'Action not found: ' . $name
    );
  } else {
    $preformatted_list[] = new Preformatted(
      'Validation',
      'Invalid confirmation code'
    );
  }

  $_SESSION['preformatted_list'] = $preformatted_list;
  header('Location: #' . $name);
  exit;
} else {
  $preformatted_list = $_SESSION['preformatted_list'] ?? $preformatted_list;
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full <?= $dark_mode_helper->isDarkModeEnabled() ? 'dark' : '' ?>">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MY</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github-dark.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>
  <script>hljs.highlightAll();</script>
  <script src="https://cdn.tailwindcss.com?plugins=forms,typography,aspect-ratio"></script>
</head>
<body class="h-full p-2 font-sans bg-slate-100 text-slate-700 dark:bg-slate-900 dark:text-slate-50 flex flex-row items-stretch">
<aside class="p-4 flex flex-col items-stretch gap-y-4 overflow-y-auto" style="flex: 1;">
  <?php foreach ($action_manager->getActionRegistry()->resolveAll() as $action): ?>
    <article
      id="<?= $action->getName() ?>"
      class="bg-white dark:bg-slate-800 shadow-lg rounded p-4"
    >
      <header class="flex flex-row items-center gap-4">
        <section class="grow flex flex-col items-stretch break-all">
          <div class="text-base"><?= $action->getName() ?></div>
          <div class="text-xs text-gray-300 dark:text-gray-600"><?= $action_helper->resolveLocation($action) ?></div>
        </section>
        <section class="flex flex-row items-center gap-2">
          <?php if ($flags_helper->hasAny($action)): ?>
            <section class="flex flex-row item-center bg-gray-500/10 dark:bg-black/50 shadow-inner rounded-full gap-2 px-2 py-1">
              <?php foreach ($flags_helper->resolveAll($action) as $flag): ?>
                <ion-icon name="<?= $flag[$flags_helper::KEY_ICON] ?>" class="<?= $flag[$flags_helper::KEY_CLASSES] ?? '' ?>"></ion-icon>
              <?php endforeach; ?>
            </section>
          <?php endif; ?>
          <?php if ($confirmation_helper->validate($action)->isValid()): ?>
            <form
              id="<?= $action_helper->resolveFormId($action) ?>"
              method="post"
              action="#<?= $action->getName() ?>"
            >
              <input
                type="hidden"
                name="<?= $action_helper->resolveActionInputName() ?>"
                value="<?= $action->getName() ?>"
              />
              <?php if ($action->isRisky()): ?>
                <input
                  type="hidden"
                  name="<?= $confirmation_helper->resolveCodeInputName() ?>"
                  value="<?= $confirmation_helper->resolveCode($action) ?>"
                />
              <?php endif; ?>
              <button
                class="w-8 h-8 border-none rounded bg-transparent hover:bg-slate-500/10 focus:bg-slate-500/10 active:bg-slate-500/25 transition-all ease-in-out duration-300 flex items-center justify-center"
              >
                <ion-icon name="flash"></ion-icon>
              </button>
            </form>
          <?php endif; ?>
        </section>
      </header>

      <?php if ($parameters_helper->hasAny($action) || $action->isRisky() || $confirmation_helper->validate($action)->isInvalid()): ?>
        <section>
          <?php if ($confirmation_helper->validate($action)->isValid()): ?>
            <ul class="list-none flex flex-col items-stretch gap-2 pt-4">
              <?php foreach ($parameters_helper->resolveAll($action) as $parameter): ?>
                <?php if ($parameters_helper->resolveTypeName($parameter) === 'bool'): ?>
                  <li>
                    <label
                      for="<?= $parameters_helper->resolveInputName($parameter) ?>"
                      class="px-2 mb-2 block text-xs font-medium text-gray-600 dark:text-gray-300"
                    >
                      <?= $parameter->getName() ?>
                    </label>
                    <select
                      id="<?= $parameters_helper->resolveInputName($parameter) ?>"
                      name="<?= $parameters_helper->resolveInputName($parameter) ?>"
                      form="<?= $action_helper->resolveFormId($action) ?>"
                      class="block w-full p-2 text-xs text-gray-900 border border-gray-300 rounded bg-gray-50 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 font-mono"
                      required
                    >
                      <option
                        value="1"
                        <?= $parameters_helper->resolveDefaultValue($parameter) === true ? 'selected' : '' ?>
                      >
                        true
                      </option>
                      <option
                        value="0"
                        <?= $parameters_helper->resolveDefaultValue($parameter) === false ? 'selected' : '' ?>
                      >
                        false
                      </option>
                    </select>
                  </li>
                <?php else: ?>
                  <li>
                    <label
                      for="<?= $parameters_helper->resolveInputName($parameter) ?>"
                      class="px-2 mb-2 block text-xs font-medium text-gray-600 dark:text-gray-300"
                    >
                      <?= $parameter->getName() ?>
                    </label>
                    <input
                      type="text"
                      id="<?= $parameters_helper->resolveInputName($parameter) ?>"
                      name="<?= $parameters_helper->resolveInputName($parameter) ?>"
                      value="<?= $parameters_helper->resolveDefaultValue($parameter) ?>"
                      form="<?= $action_helper->resolveFormId($action) ?>"
                      class="block w-full p-2 text-gray-900 border border-gray-300 rounded bg-gray-50 text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 placeholder:text-gray-400 dark:placeholder:text-gray-500 font-mono"
                      required
                    />
                  </li>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if ($action->isRisky()): ?>
                <li>
                  <label
                    for="<?= $confirmation_helper->resolveConfirmationInputName() ?>"
                    class="px-2 mb-2 block text-xs font-medium text-gray-600 dark:text-gray-300"
                  >
                    Confirmation code
                  </label>
                  <input
                    type="text"
                    id="<?= $confirmation_helper->resolveConfirmationInputName() ?>"
                    name="<?= $confirmation_helper->resolveConfirmationInputName() ?>"
                    placeholder="<?= $confirmation_helper->resolveCode($action) ?>"
                    form="<?= $action_helper->resolveFormId($action) ?>"
                    class="block w-full p-2 text-gray-900 border border-gray-300 rounded bg-gray-50 text-xs focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 placeholder:text-gray-400 dark:placeholder:text-gray-500 font-mono"
                    required
                  />
                </li>
              <?php endif; ?>
            </ul>
          <?php else: ?>
            <p class="text-red-500 text-sm pt-3"><?= $confirmation_helper->validate($action)->getMessage() ?></p>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</aside>

<main class="p-4 flex flex-col items-stretch gap-y-4 overflow-y-auto" style="flex: 2;">
  <?php foreach ($preformatted_list as $key => $value): ?>
    <article class="bg-white dark:bg-slate-800 shadow-lg rounded">
      <header class="py-3 px-4 flex flex-row items-center gap-4">
        <section class="flex flex-row items-center gap-2">
          <?php foreach (range(1, 3) as $ignored): ?>
            <div
              class="w-3 h-3 rounded-full bg-black/10 dark:bg-black/25 hover:bg-sky-500 dark:hover:bg-sky-500 shadow-inner transition-all ease-in-out duration-150"
            ></div>
          <?php endforeach; ?>
        </section>
        <section class="grow flex flex-row items-center">
          <?= $value->getTitle() ?>
        </section>
        <section class="flex flex-row items-center">
          <button
            onclick="copyInnerText('preformatted-<?= $key ?>')"
            class="w-8 h-8 border-none rounded bg-transparent hover:bg-slate-500/10 focus:bg-slate-500/10 active:bg-slate-500/25 transition-all ease-in-out duration-300 flex items-center justify-center"
          >
            <ion-icon name="clipboard"></ion-icon>
          </button>
        </section>
      </header>
      <section class="p-1 pt-0 text-sm">
        <pre><code
            id="preformatted-<?= $key ?>"
            class="rounded shadow-inner whitespace-pre-wrap break-all language-<?= $value->getLanguage() ?>"
          ><?= $value->getBody() ?></code></pre>
      </section>
    </article>
  <?php endforeach; ?>
</main>

<button
  onclick="toggleDarkMode()"
  class="bg-indigo-800 hover:bg-indigo-600 text-slate-50 dark:bg-sky-100 dark:hover:bg-white dark:text-slate-700 border-none flex justify-center items-center fixed bottom-12 right-12 w-12 h-12 rounded-lg shadow-lg text-2xl transition ease-in-out duration-1000"
>
  <ion-icon
    id="dark-mode-switch-icon"
    name="<?= $dark_mode_helper->resolveIcon() ?>"
  ></ion-icon>
</button>

<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
<script>
  function copyInnerText(id) {
    let element = document.getElementById(id);

    if (element != null) {
      navigator.clipboard.writeText(element.innerText);
    }
  }

  function setCookie(key, value) {
    document.cookie = key + '=' + value + '; expires=Fri, 31 Dec 9999 23:59:59 UTC';
  }

  function toggleDarkMode() {
    let htmlElement = document.documentElement;
    htmlElement.classList.toggle('dark');
    document.getElementById('dark-mode-switch-icon').setAttribute(
      'name',
      htmlElement.classList.contains('dark') ? 'sunny' : 'moon',
    );
    setCookie(
      '<?= $dark_mode_helper->resolveCookieKey() ?>',
      htmlElement.classList.contains('dark') ? 1 : 0,
    );
  }

  tailwind.config = {
    darkMode: 'class',
  };
</script>
</body>
</html>
