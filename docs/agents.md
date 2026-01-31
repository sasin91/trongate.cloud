# Agent Instructions:

This document serves as the primary technical specification and behavior guide for AI agents contributing to this project. 

## 1. Core Principles & Philosophy
- **Pure PHP**: Avoid heavy abstractions, complex ORMs, or unnecessary third-party libraries.
- **Simplicity Over Complexity**: If a task can be solved with "lower level" code that is faster and more readable, choose it.
- **HMVC Architecture**: Every feature exists within a self-contained module. Modules should be independently portable where possible.
- **Module Market**: Before building a common feature (e.g., Auth, Blog, News), check the [Trongate Module Market](https://trongate.io/module-market) for existing high-quality modules.

## 2. Technical Standards (Backend)

### 2.1 Code Style & Indentation
- **Bracing Style**: Strictly follow **K&R Style**. Opening braces `{` must be on the **same line** as the declaration.
- **Indentation**: Use **4 spaces** (never tabs).
- **Method Visibility**: There is no need for PHP method visibility modifiers (`public`, `protected`, `private`) in Trongate. As a rule of thumb, write without visibility modifiers unless specifically required.
- **Underscore Prefix**: Prefix methods with an underscore (`_`) if they are for internal use and should **not** be accessible via a URL. In addition a Controller may also call `block_url_invocation($this->module_name)` to prevent URL invocation of any method.

### 2.2 Database Logic & Migrations
- **Location**: All data persistence and retrieval must reside in the module's **Model**.
- **SQL Execution**:
    - Use `query()` and `query_bind()` with **HEREDOC** for custom or complex SQL.
    - Use built-in helpers (`insert()`, `update()`, `delete()`, `get_where()`) only for simple CRUD operations.
    - **Security**: Always use prepared statements/binding for user-supplied data to prevent SQL injection.
- **Migrations (SQL Transfer)**: Trongate uses a simplified SQL-based migration system for development:
    - When `ENV='dev'`, `Core.php` scans the module directory for `.sql` files on every request.
    - **CRITICAL**: The `.sql` file must be placed directly in the module's root directory (e.g., `modules/my_module/my_table.sql`) to be detected. Files in subdirectories (like `assets/sql`) will be ignored.
    - If found, it redirects to the **Module Import Wizard** (`trongate_control`) to run or delete them.
    - This is the standard way to share schema changes during development.

### 2.3 Controller Responsibility
- **Role**: Handle routing, input validation, and template data preparation.
- **Simplicity**: Keep controllers lean. Offload data logic to the Model and complex business logic to internal (underscore-prefixed) methods.
### 2.4 View Rendering
- **Full HTML Pages**: Use `$this->view('view_name', $data)` when the view file contains the complete HTML structure (common for login pages, specialized landing pages, or error pages).
- **Templated Pages**: When a view requires a wrapper (header/footer/nav), do **not** use `view()`. Instead, load the `templates` module and call the specific template method:
    ```php
    $this->module('templates');
    $this->templates->public($data); // For public-facing pages
    $this->templates->admin($data);  // For admin dashboard pages
    ```


### 3.1 Styling & Icons
- **Trongate CSS**: Utilize the minimalist built-in framework.
    - Prefer standard HTML elements (forms are auto-styled).
    - Use Flexbox utility classes (`.flex-row`, `.flex-1`, etc.) and containers (`.container`).
    - Focus responsive testing on the **550px breakpoint**.
- **Trongate Icons**: Use the integrated CSS mask system. Implement via `<i class="tg tg-icon-name"></i>`.

### 3.2 Interactivity (MX)
- **Trongate MX**: Use for AJAX-driven interactivity. Always prefer MX attributes (`mx-get`, `mx-target`, `mx-swap`) over custom JavaScript where possible.

### 3.3 Module Assets
- **Mechanism**: Assets located within a module's directory are served via a special URL suffix (default: `_module`). The framework's `Core.php` intercepts these requests and securely streams the file.
- **URL Pattern**: `base_url() . '[module_name]_module/[subdirectory]/[file]'`
    - *Example*: `members_module/css/custom.css` maps to `modules/members/css/custom.css`.
    - The `_module` suffix is **verify** important for the framework to correctly serve the asset.
- **Allowed Directories**: Assets **must** reside in allowed subdirectories (e.g., `css`, `js`, `images`, `fonts`, `json`) directly under the module root.
    - **CRITICAL**: Do **NOT** use an `assets` subdirectory (e.g., `modules/my_module/assets/css`). This is a V1 pattern. The correct path is `modules/my_module/css`.
    - **api.json**: This file is a remnant of V1 Api Manager and should not be created.
- **Security**: The dispatcher strictly allows only safe MIME types and extensions. PHP, SQL, and config files are blocked.

## 4. Naming Conventions & Structure
- **Directories/Modules**: `snake_case`.
- **Controllers**: `PascalCase` filenames/classes (e.g., `Workouts.php`).
- **Models**: `PascalCase` filenames/classes with `_model` suffix (e.g., `Workouts_model.php`).
- **Methods & Variables**: Always `snake_case`.
- **Child Modules**: Located in a subdirectory of a parent module (e.g., `modules/parent/child/Child.php`).
    - **URL Routing**: Accessed via a dash separator (e.g., `base_url/parent-child`).
    - **Namespace**: The class name should match the child directory name (PascalCase).

## 5. Collaborative Guidelines
- **Performance First**: Code should execute with minimal latency.
- **Trace Knowledge**: Refer to the `/engine/` core files or the official Trongate documentation linked in `llm.txt`.
- **Maintain Documentation**: Proactively update this file or `llm.txt` if architectural patterns evolve.
