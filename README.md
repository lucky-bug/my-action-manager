# my-action-manager

## About

This is a helper/utility project meant to help developers.

## Installation

1. Clone the repository to a directory, `00my` for example.
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

  (function() {
    $action_manager = ActionManager::instance();
    
    $action_manager->load([
      'risky' => function() {
        echo 'Hello, World!';
      },
      'safe'  => new Action(
        function() {
          echo 'Hello, World';
        },
        false
      ),
    ]);

    $action_manager->run();
  })();

  ```
3. Run the code.

## Screenshots

### Dark mode

<img width="1245" alt="Dark mode" src="https://user-images.githubusercontent.com/38420292/211242516-ad598218-198a-4ea1-bab1-858c4fb60b8f.png">

### Light mode

<img width="1248" alt="Light mode" src="https://user-images.githubusercontent.com/38420292/211242428-9e191d3f-8294-47ca-a51d-d7a6d82a9bb2.png">
