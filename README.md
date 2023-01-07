# my-action-manager

## About

This is a helper/utility project meant to help developers.

## Installation

1. Clone the repository to a directory, "00my" for example.
  ```bash
  git clone https://github.com/lucky-bug/my-action-manager.git 00my
  ```
2. Create a PHP file with the following contents:
  ```php
  <?php

  declare(strict_types = 1);

  require_once '00my/bootstrap.php';

  use My\Action;
  use My\ActionManager;

  $actions = [
    'risky' => function() {
      echo 'Hello, World!';
    },
    'safe'  => new Action(
      function() {
        echo 'Hello, World';
      },
      false
    ),
  ];

  $action_manager = ActionManager::instance();
  $action_manager->load($actions);
  $action_manager->run();

  ```
3. Run the code.
