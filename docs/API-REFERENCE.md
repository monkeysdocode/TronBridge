# TronBridge Model API Reference
> Complete public API documentation for TronBridge Enhanced Model

This document provides comprehensive reference documentation for all public methods available in TronBridge's enhanced Model class. The API is organized by functional category for easy navigation.

## 📋 **Table of Contents**

- [🏗️ Constructor & Connection](#️-constructor--connection)
- [📊 Core CRUD Operations](#-core-crud-operations)
- [🔍 Advanced Query Methods](#-advanced-query-methods)
- [⚡ Enhanced Aggregate Functions](#-enhanced-aggregate-functions)
- [✅ Existence & Validation Helpers](#-existence--validation-helpers)
- [📊 Enhanced Data Retrieval](#-enhanced-data-retrieval)
- [⚡ Batch Operations](#-batch-operations)
- [🧮 Expression Methods (Atomic Operations)](#-expression-methods-atomic-operations)
- [🔄 Advanced CRUD Operations](#-advanced-crud-operations)
- [📄 Pagination & Collection Operations](#-pagination--collection-operations)
- [📅 Time-Based Helpers](#-time-based-helpers)
- [🔄 Transaction Management](#-transaction-management)
- [🏭 Factory Methods](#-factory-methods)
- [🐛 Debug & Monitoring](#-debug--monitoring)
- [🛠️ Utility & Helper Methods](#️-utility--helper-methods)
- [🔍 Database Introspection](#-database-introspection)
- [💾 Raw SQL Methods](#-raw-sql-methods)

---

## 🏗️ **Constructor & Connection**

### **`__construct()`**
Create Model instance with optional database connection and configuration.

```php
public function __construct(
    ?string $current_module = null, 
    $connection = null, 
    array $options = []
): void
```

#### **Parameters**
- `$current_module` - Default table name for operations
- `$connection` - Database connection string or null for default
- `$options` - Configuration options array

#### **Connection String Examples**
```php
// Default MySQL connection (uses config)
$model = new Model('users');

// SQLite connection
$model = new Model('users', 'sqlite:./database.sqlite');

// PostgreSQL connection  
$model = new Model('users', 'postgresql:host=localhost;dbname=app;user=postgres;pass=secret');

// MySQL with custom connection
$model = new Model('users', 'mysql:host=db.example.com;dbname=app;user=app;pass=secret');
```

#### **Options Array**
```php
$options = [
    'lightweightMode' => false,    // Enable all optimizations
    'bulkThreshold' => 50,         // Auto-bulk detection threshold
    'autoOptimize' => true,        // Enable automatic optimizations
    'debug' => false              // Enable debug mode
];
$model = new Model('users', null, $options);
```

### **Static Factory Methods**

#### **`createForBulk()`**
Create Model instance optimized for bulk operations.

```php
public static function createForBulk(
    ?string $current_module = null, 
    $connection = null, 
    array $additionalOptions = []
): self
```

#### **`createForLongRunning()`** 
Create Model instance optimized for long-running processes.

```php
public static function createForLongRunning(
    ?string $current_module = null, 
    $connection = null, 
    array $additionalOptions = []
): self
```

---

## 📊 **Core CRUD Operations**

### **`insert()`**
Insert single record with auto-bulk detection.

```php
public function insert(
    array $data, 
    ?string $target_table = null
): int
```

#### **Parameters**
- `$data` - Associative array of column => value pairs
- `$target_table` - Target table name (optional)

#### **Returns**
- `int` - ID of inserted record

#### **Examples**
```php
// Insert user record
$userId = $model->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'created_at' => date('Y-m-d H:i:s')
], 'users');

// Insert with auto-detection of bulk operations
for ($i = 0; $i < 100; $i++) {
    $model->insert(['data' => "Record $i"], 'logs'); // Auto-optimizes after threshold
}
```

### **`update()`**
Update single record by ID.

```php
public function update(
    int $id, 
    array $data, 
    ?string $target_table = null
): bool
```

#### **Parameters**
- `$id` - Primary key of record to update
- `$data` - Associative array of column => value pairs
- `$target_table` - Target table name (optional)

#### **Returns**
- `bool` - Success status

#### **Examples**
```php
// Update user profile
$success = $model->update(123, [
    'name' => 'Jane Smith',
    'email' => 'jane@example.com',
    'updated_at' => date('Y-m-d H:i:s')
], 'users');
```

### **`delete()`**
Delete record by ID.

```php
public function delete(int $id, ?string $target_table = null): bool
```

#### **Parameters**
- `$id` - Primary key of record to delete
- `$target_table` - Target table name (optional)

#### **Returns**
- `bool` - Success status

#### **Examples**
```php
// Delete user account
$deleted = $model->delete(123, 'users');
```

---

## 🔍 **Advanced Query Methods**

### **`get()`**
Get all records with optional ordering and pagination.

```php
public function get(
    ?string $order_by = null, 
    ?string $target_table = null, 
    ?int $limit = null, 
    int $offset = 0
): array
```

#### **Parameters**
- `$order_by` - ORDER BY clause (e.g., 'id desc', 'name asc')
- `$target_table` - Target table name (optional)
- `$limit` - Maximum number of records
- `$offset` - Number of records to skip

#### **Returns**
- `array` - Array of database records as objects

#### **Examples**
```php
// Get all users ordered by ID descending
$users = $model->get('id desc', 'users');

// Get latest 10 posts with pagination
$posts = $model->get('created_at desc', 'posts', 10, 20);

// Multiple column ordering
$products = $model->get('category_id asc, price desc', 'products');
```

### **`get_where()`**
Get single record by ID.

```php
public function get_where(int $id, ?string $target_table = null): object|false
```

#### **Parameters**
- `$id` - Primary key value
- `$target_table` - Target table name (optional)

#### **Returns**
- `object|false` - Database record as object, or false if not found

#### **Examples**
```php
// Get user by ID
$user = $model->get_where(123, 'users');
if ($user) {
    echo "Found user: " . $user->name;
}
```

### **`get_one_where()`**
Get single record by column value.

```php
public function get_one_where(
    string $column, 
    $value, 
    ?string $target_table = null
): object|false
```

#### **Parameters**
- `$column` - Column name for WHERE condition
- `$value` - Value to match against
- `$target_table` - Target table name (optional)

#### **Returns**
- `object|false` - Database record as object, or false if not found

#### **Examples**
```php
// Get user by email
$user = $model->get_one_where('email', 'john@example.com', 'users');

// Get product by SKU
$product = $model->get_one_where('sku', 'ABC-123', 'products');
```

### **`get_many_where()`**
Get all records matching column value.

```php
public function get_many_where(
    string $column, 
    $value, 
    ?string $target_table = null
): array
```

#### **Parameters**
- `$column` - Column name for WHERE condition
- `$value` - Value to match against
- `$target_table` - Target table name (optional)

#### **Returns**
- `array` - Array of matching database records as objects

#### **Examples**
```php
// Get all active users
$activeUsers = $model->get_many_where('status', 'active', 'users');

// Get all products in a category
$products = $model->get_many_where('category_id', 5, 'products');
```

### **`get_where_custom()`**
Advanced WHERE clause queries with operator support.

```php
public function get_where_custom(
    string $column,
    $value,
    string $operator = '=',
    string $order_by = 'id',
    ?string $target_table = null,
    ?int $limit = null,
    ?int $offset = null
): array
```

#### **Parameters**
- `$column` - Column name for WHERE condition
- `$value` - Value to compare against
- `$operator` - Comparison operator ('=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE')
- `$order_by` - Column name for ORDER BY
- `$target_table` - Target table name (optional)
- `$limit` - Maximum number of records
- `$offset` - Number of records to skip

#### **Returns**
- `array` - Array of matching database records as objects

#### **Examples**
```php
// Find active users
$activeUsers = $model->get_where_custom('status', 'active');

// Pattern matching with pagination
$results = $model->get_where_custom('name', 'John', 'LIKE', 'created_at', 'users', 10, 0);

// Numeric comparison
$expensiveProducts = $model->get_where_custom('price', 1000, '>', 'price', 'products');
```

### **`get_where_in()`**
Get records where column value exists in array.

```php
public function get_where_in(
    string $column, 
    array $values, 
    ?string $target_table = null, 
    string $return_type = 'object'
): array
```

#### **Parameters**
- `$column` - Column name to filter by
- `$values` - Array of values to match against
- `$target_table` - Target table name (optional)
- `$return_type` - Result format ('object' or 'array')

#### **Returns**
- `array` - Array of matching database records

#### **Examples**
```php
// Get users by multiple IDs
$users = $model->get_where_in('id', [1, 2, 3, 4, 5], 'users');

// Get products in multiple categories
$products = $model->get_where_in('category_id', [10, 20, 30], 'products', 'array');
```

### **`count()`**
Count all records in table.

```php
public function count(?string $target_table = null): int
```

#### **Examples**
```php
// Count all users
$totalUsers = $model->count('users');
```

### **`count_where()`**
Count records matching condition with operator support.

```php
public function count_where(
    string $column, 
    $value, 
    string $operator = '=', 
    ?string $target_table = null
): int
```

#### **Examples**
```php
// Count active users
$activeCount = $model->count_where('status', 'active', '=', 'users');

// Count expensive products
$expensiveCount = $model->count_where('price', 1000, '>', 'products');
```

### **`count_rows()`**
Count records matching single column equality.

```php
public function count_rows(
    string $column, 
    $value, 
    ?string $target_table = null
): int
```

#### **Examples**
```php
// Count users in specific city
$cityCount = $model->count_rows('city', 'Toronto', 'users');
```

### **`get_max()`**
Get maximum ID value from specified table.

```php
public function get_max(?string $target_table = null): int
```

#### **Parameters**
- `$target_table` - Target table name (defaults to current module)

#### **Returns**
- `int` - Maximum ID value, or 0 if table is empty

#### **Examples**
```php
// Get highest user ID
$maxUserId = $model->get_max('users');

// Check latest order ID
$latestOrderId = $model->get_max('orders');

// Get max ID from current module table
$maxId = $model->get_max();
```

---

## ⚡ **Enhanced Aggregate Functions**

### **`sum()`**
Calculate sum of numeric column with optional WHERE condition.

```php
public function sum(
    string $column, 
    ?string $target_table = null, 
    ?string $where_column = null, 
    $where_value = null
): float
```

#### **Parameters**
- `$column` - Column name to sum (validated for safety)
- `$target_table` - Target table name (defaults to current module)
- `$where_column` - Optional WHERE condition column
- `$where_value` - Optional WHERE condition value

#### **Returns**
- `float` - Sum of column values, 0.0 if no matching records

#### **Examples**
```php
// Calculate total revenue
$totalRevenue = $model->sum('amount', 'orders');

// Calculate completed orders revenue
$completedRevenue = $model->sum('amount', 'orders', 'status', 'completed');

// Calculate user's total spending
$userSpending = $model->sum('total', 'orders', 'user_id', 123);
```

### **`avg()`**
Calculate average of numeric column with optional WHERE condition.

```php
public function avg(
    string $column, 
    ?string $target_table = null, 
    ?string $where_column = null, 
    $where_value = null
): float
```

#### **Examples**
```php
// Calculate average product rating
$avgRating = $model->avg('rating', 'reviews');

// Calculate average price for category
$avgPrice = $model->avg('price', 'products', 'category_id', 5);
```

### **`min()`**
Find minimum value in column with optional WHERE condition.

```php
public function min(
    string $column, 
    ?string $target_table = null, 
    ?string $where_column = null, 
    $where_value = null
): mixed
```

#### **Examples**
```php
// Find oldest user registration
$oldestUser = $model->min('created_at', 'users');

// Find lowest price in category
$lowestPrice = $model->min('price', 'products', 'category_id', 3);
```

### **`max()`**
Find maximum value in column with optional WHERE condition.

```php
public function max(
    string $column, 
    ?string $target_table = null, 
    ?string $where_column = null, 
    $where_value = null
): mixed
```

#### **Examples**
```php
// Find highest price
$highestPrice = $model->max('price', 'products');

// Find latest order date for user
$latestOrder = $model->max('order_date', 'orders', 'user_id', 123);

// Find highest rating in category
$topRating = $model->max('rating', 'reviews', 'category', 'electronics');
```

---

## ✅ **Existence & Validation Helpers**

### **`exists()`**
Check if record exists with specified column value (optimized for large tables).

```php
public function exists(string $column, $value, ?string $target_table = null): bool
```

#### **Parameters**
- `$column` - Column name for condition (validated for safety)
- `$value` - Value to check for existence
- `$target_table` - Target table name (defaults to current module)

#### **Returns**
- `bool` - True if record exists, false otherwise

#### **Examples**
```php
// Check if email exists (faster than count for large tables)
if ($model->exists('email', 'user@example.com', 'users')) {
    echo "Email already registered";
}

// Check if product SKU exists
if ($model->exists('sku', 'ABC-123', 'products')) {
    echo "SKU already in use";
}

// Check if order exists for user
if ($model->exists('user_id', 123, 'orders')) {
    echo "User has orders";
}
```

### **`is_unique()`**
Check if column value is unique (excluding specified record).

```php
public function is_unique(
    string $column, 
    $value, 
    ?string $target_table = null, 
    ?int $exclude_id = null
): bool
```

#### **Parameters**
- `$column` - Column name to check for uniqueness (validated)
- `$value` - Value to check for uniqueness
- `$target_table` - Target table name (defaults to current module)
- `$exclude_id` - Optional ID to exclude from uniqueness check

#### **Returns**
- `bool` - True if value is unique, false if duplicate exists

#### **Examples**
```php
// Check username uniqueness during user update
if (!$model->is_unique('username', 'john_doe', 'users', $currentUserId)) {
    echo "Username already taken";
}

// Check email uniqueness for new registration
if (!$model->is_unique('email', 'user@example.com', 'users')) {
    echo "Email already registered";
}

// Check product SKU uniqueness during update
if (!$model->is_unique('sku', 'ABC-123', 'products', $productId)) {
    echo "SKU already exists";
}
```

---

## 📊 **Enhanced Data Retrieval**

### **`get_first()`**
Get first record from table with optional ordering.

```php
public function get_first(
    ?string $order_by = 'id', 
    ?string $target_table = null
): object|false
```

#### **Parameters**
- `$order_by` - Column and direction for ordering (e.g., 'created_at', 'id desc')
- `$target_table` - Target table name (defaults to current module)

#### **Returns**
- `object|false` - First record as object, or false if table is empty

#### **Examples**
```php
// Get first user by creation date
$firstUser = $model->get_first('created_at', 'users');

// Get alphabetically first product
$firstProduct = $model->get_first('name', 'products');

// Get oldest order (default id ordering)
$oldestOrder = $model->get_first(null, 'orders');
```

### **`get_last()`**
Get last record from table with optional ordering.

```php
public function get_last(
    ?string $order_by = 'id', 
    ?string $target_table = null
): object|false
```

#### **Examples**
```php
// Get latest user registration
$latestUser = $model->get_last('created_at', 'users');

// Get highest ID order
$latestOrder = $model->get_last('id', 'orders');

// Get most recent blog post
$recentPost = $model->get_last('published_at', 'posts');
```

### **`get_random()`**
Get random records from table with cross-database compatibility.

```php
public function get_random(int $limit = 1, ?string $target_table = null): array
```

#### **Parameters**
- `$limit` - Number of random records to retrieve (default: 1)
- `$target_table` - Target table name (defaults to current module)

#### **Returns**
- `array` - Array of random records as objects

#### **Examples**
```php
// Get single random product
$randomProduct = $model->get_random(1, 'products');

// Get 5 random featured articles
$randomArticles = $model->get_random(5, 'featured_articles');

// Get random user for spotlight
$spotlightUser = $model->get_random(1, 'users');
```

### **`pluck()`**
Extract single column values as array with optional key column.

```php
public function pluck(
    string $column, 
    ?string $target_table = null, 
    ?string $key_column = null
): array
```

#### **Parameters**
- `$column` - Column name to extract values from (validated)
- `$target_table` - Target table name (defaults to current module)
- `$key_column` - Optional column to use as array keys (validated)

#### **Returns**
- `array` - Array of column values, optionally keyed by key_column

#### **Examples**
```php
// Get array of user emails
$emails = $model->pluck('email', 'users');
// Returns: ['user1@example.com', 'user2@example.com', ...]

// Get user emails keyed by ID
$emailsById = $model->pluck('email', 'users', 'id');
// Returns: [1 => 'user1@example.com', 2 => 'user2@example.com', ...]

// Get product names keyed by SKU
$productNames = $model->pluck('name', 'products', 'sku');
// Returns: ['ABC-123' => 'Widget A', 'DEF-456' => 'Widget B', ...]
```

### **`get_distinct()`**
Get distinct values from column.

```php
public function get_distinct(
    string $column, 
    ?string $target_table = null, 
    ?string $order_by = null
): array
```

#### **Parameters**
- `$column` - Column name to get distinct values from (validated)
- `$target_table` - Target table name (defaults to current module)
- `$order_by` - Optional ordering for results

#### **Returns**
- `array` - Array of distinct values from the column

#### **Examples**
```php
// Get all product categories
$categories = $model->get_distinct('category', 'products');

// Get all countries with ordering
$countries = $model->get_distinct('country', 'users', 'country ASC');

// Get all order statuses
$statuses = $model->get_distinct('status', 'orders');
```

---

## 🔄 **Advanced CRUD Operations**

### **`upsert()`**
Insert or update record based on unique column (UPSERT operation).

```php
public function upsert(
    array $data, 
    string $unique_column, 
    ?string $target_table = null
): int
```

#### **Parameters**
- `$data` - Associative array of column => value pairs
- `$unique_column` - Column name to check for uniqueness (validated)
- `$target_table` - Target table name (defaults to current module)

#### **Returns**
- `int` - ID of inserted or updated record

#### **Examples**
```php
// Insert new user or update existing by email
$userId = $model->upsert([
    'email' => 'user@example.com',
    'name' => 'John Doe',
    'updated_at' => date('Y-m-d H:i:s')
], 'email', 'users');

// Product catalog sync with SKU as unique identifier
$productId = $model->upsert([
    'sku' => 'ABC-123',
    'name' => 'Widget Pro',
    'price' => 29.99
], 'sku', 'products');
```

### **`toggle_column()`**
Toggle boolean column value (0 to 1, 1 to 0).

```php
public function toggle_column(
    int $id, 
    string $column, 
    ?string $target_table = null
): bool
```

#### **Parameters**
- `$id` - Record ID to update
- `$column` - Boolean column name to toggle (validated)
- `$target_table` - Target table name (defaults to current module)

#### **Returns**
- `bool` - True if toggle succeeded, false otherwise

#### **Examples**
```php
// Toggle user active status
$model->toggle_column(123, 'is_active', 'users');

// Toggle product featured status
$model->toggle_column(456, 'is_featured', 'products');

// Toggle notification setting
$model->toggle_column(789, 'email_notifications', 'user_settings');
```

---

## 📄 **Pagination & Collection Operations**

### **`paginate()`**
Built-in pagination with comprehensive metadata.

```php
public function paginate(
    int $page, 
    int $per_page = 20, 
    ?string $order_by = null, 
    ?string $target_table = null
): array
```

#### **Parameters**
- `$page` - Current page number (1-based)
- `$per_page` - Records per page (default: 20)
- `$order_by` - Optional ordering clause
- `$target_table` - Target table name (defaults to current module)

#### **Returns**
- `array` - Pagination result with data and metadata

#### **Examples**
```php
// Basic pagination
$result = $model->paginate(2, 15, 'created_at desc', 'posts');
// Returns: [
//   'data' => [...],           // Array of records
//   'total' => 150,            // Total record count
//   'page' => 2,               // Current page
//   'per_page' => 15,          // Records per page
//   'total_pages' => 10,       // Total pages
//   'has_previous' => true,    // Has previous page
//   'has_next' => true,        // Has next page
//   'previous_page' => 1,      // Previous page number
//   'next_page' => 3,          // Next page number
//   'from' => 16,              // First record number
//   'to' => 30                 // Last record number
// ]

// Simple pagination without ordering
$users = $model->paginate(1, 10, null, 'users');
```

---

## 📅 **Time-Based Helpers**

### **`get_by_date_range()`**
Get records within date range.

```php
public function get_by_date_range(
    string $start_date, 
    string $end_date, 
    string $date_column = 'created_at', 
    ?string $target_table = null
): array
```

#### **Parameters**
- `$start_date` - Start date (YYYY-MM-DD format)
- `$end_date` - End date (YYYY-MM-DD format)
- `$date_column` - Column name containing date values (default: 'created_at')
- `$target_table` - Target table name (defaults to current module)

#### **Returns**
- `array` - Array of records within the date range

#### **Examples**
```php
// Get orders from January 2024
$orders = $model->get_by_date_range('2024-01-01', '2024-01-31', 'order_date', 'orders');

// Get recent user registrations
$recentUsers = $model->get_by_date_range('2024-01-01', '2024-12-31', 'created_at', 'users');

// Get posts published this year
$posts = $model->get_by_date_range('2024-01-01', '2024-12-31', 'published_at', 'posts');
```

### **`get_today()`**
Get records created today.

```php
public function get_today(
    string $date_column = 'created_at', 
    ?string $target_table = null
): array
```

#### **Examples**
```php
// Get today's orders
$todaysOrders = $model->get_today('order_date', 'orders');

// Get today's user registrations
$newUsers = $model->get_today('created_at', 'users');

// Get today's blog posts
$todaysPosts = $model->get_today('published_at', 'posts');
```

### **`get_recent()`**
Get records from the past N days.

```php
public function get_recent(
    int $days = 7, 
    string $date_column = 'created_at', 
    ?string $target_table = null
): array
```

#### **Parameters**
- `$days` - Number of days to look back (default: 7)
- `$date_column` - Column containing date values (default: 'created_at')
- `$target_table` - Target table name (defaults to current module)

#### **Returns**
- `array` - Array of records from the specified time period

#### **Examples**
```php
// Get orders from the past week
$recentOrders = $model->get_recent(7, 'order_date', 'orders');

// Get posts from the past month
$recentPosts = $model->get_recent(30, 'published_at', 'posts');

// Get user activity from past 3 days
$recentActivity = $model->get_recent(3, 'last_login', 'users');
```

---

## ⚡ **Batch Operations**

### **`insert_batch()`**
High-performance bulk insert with automatic optimization.

```php
public function insert_batch(
    string $table, 
    array $records, 
    int|null $chunkSize = null
): int
```

#### **Parameters**
- `$table` - Target table name
- `$records` - Array of associative arrays (column => value pairs)
- `$chunkSize` - Optional chunk size override (auto-calculated if null)

#### **Returns**
- `int` - Number of records successfully inserted

#### **Examples**
```php
// Bulk insert with auto-optimization
$inserted = $model->insert_batch('users', [
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'jane@example.com'],
    // ... thousands more records
]);

// With custom chunk size
$inserted = $model->insert_batch('products', $productData, 500);
```

### **`update_batch()`**
Intelligent batch update with strategy selection.

```php
public function update_batch(
    string $table, 
    string $identifierField, 
    array $updates, 
    int|null $chunkSize = null
): int
```

#### **Parameters**
- `$table` - Target table name
- `$identifierField` - Primary key field name
- `$updates` - Array of update records: `[['id' => 1, 'data' => ['name' => 'New Name']], ...]`
- `$chunkSize` - Optional chunk size override

#### **Returns**
- `int` - Total number of records successfully updated

#### **Examples**
```php
// Moderate batch update (uses CASE statements)
$updates = [
    ['id' => 1, 'data' => ['name' => 'John Doe', 'email' => 'john@example.com']],
    ['id' => 2, 'data' => ['name' => 'Jane Smith', 'email' => 'jane@example.com']]
];
$updated = $model->update_batch('users', 'id', $updates);

// Massive batch update (automatically uses temp table strategy)
$massiveUpdates = []; // 5,000+ records
foreach ($csvData as $row) {
    $massiveUpdates[] = ['id' => $row['id'], 'data' => ['price' => $row['new_price']]];
}
$model->update_batch('products', 'id', $massiveUpdates);
```

### **`delete_batch()`**
Batch delete operations with validation.

```php
public function delete_batch(
    string $table, 
    array $identifiers, 
    int|null $chunkSize = null
): int
```

#### **Parameters**
- `$table` - Target table name
- `$identifiers` - Array of primary key values to delete
- `$chunkSize` - Optional chunk size override

#### **Returns**
- `int` - Number of records successfully deleted

#### **Examples**
```php
// Delete multiple users by ID
$deleted = $model->delete_batch('users', [1, 2, 3, 4, 5]);

// Large batch deletion with custom chunk size
$deleted = $model->delete_batch('old_logs', $expiredLogIds, 1000);
```

### **`update_batch()`**
Intelligent batch update with strategy selection.

```php
public function update_batch(
    string $table, 
    string $identifierField, 
    array $updates, 
    int|null $chunkSize = null
): int
```

#### **Parameters**
- `$table` - Target table name
- `$identifierField` - Primary key field name
- `$updates` - Array of update records: `[['id' => 1, 'data' => ['name' => 'New Name']], ...]`
- `$chunkSize` - Optional chunk size override

#### **Returns**
- `int` - Total number of records successfully updated

#### **Features**
- **Automatic Strategy Selection** - Uses CASE statements for moderate datasets, temporary tables for large datasets
- **Database-Specific Optimizations** - MySQL, SQLite, PostgreSQL specific performance enhancements
- **Intelligent Performance Analysis** - Automatically chooses optimal approach based on dataset size

#### **Examples**
```php
// Moderate batch update (uses CASE statements)
$updates = [
    ['id' => 1, 'data' => ['name' => 'John Doe', 'email' => 'john@example.com']],
    ['id' => 2, 'data' => ['name' => 'Jane Smith', 'email' => 'jane@example.com']]
];
$updated = $model->update_batch('users', 'id', $updates);

// Massive batch update (automatically uses temp table strategy)
$massiveUpdates = []; // 5,000+ records
foreach ($csvData as $row) {
    $massiveUpdates[] = ['id' => $row['id'], 'data' => ['price' => $row['new_price']]];
}
$model->update_batch('products', 'id', $massiveUpdates); // Uses temp table for performance
```

---

## 🧮 **Expression Methods (Atomic Operations)**

### **`update_with_expressions()`**
Update record with atomic expression support.

```php
public function update_with_expressions(
    int $id,
    array $data = [],
    array $expressions = [],
    ?string $target_table = null,
    array $allowed_columns = []
): bool
```

#### **Parameters**
- `$id` - Record ID to update
- `$data` - Standard data for parameter binding
- `$expressions` - Associative array of column => expression pairs
- `$target_table` - Target table name (optional)
- `$allowed_columns` - Columns allowed in expressions (security)

#### **Returns**
- `bool` - Success status

#### **Examples**
```php
// Atomic counter updates
$model->update_with_expressions($postId, [], [
    'view_count' => 'view_count + 1',
    'last_viewed' => 'NOW()'
], 'posts', ['view_count']);

// Mixed parameter and expression updates
$model->update_with_expressions($userId, 
    ['last_ip' => $userIP],              // Parameter binding
    ['login_count' => 'login_count + 1'], // Expression
    'users', 
    ['login_count']                      // Allow login_count in expressions
);
```

### **`update_where_with_expressions()`**
Bulk update with WHERE clause and expression support.

```php
public function update_where_with_expressions(
    string $column,
    $column_value,
    array $data = [],
    array $expressions = [],
    ?string $target_table = null,
    array $allowed_columns = []
): bool
```

#### **Examples**
```php
// Bulk price increase for category
$model->update_where_with_expressions('category_id', 5, [], [
    'price' => 'price * 1.15',       // 15% price increase
    'last_updated' => 'NOW()'
], 'products', ['price']);

// Reset counters for active users
$model->update_where_with_expressions('status', 'active', [], [
    'login_attempts' => '0',
    'last_reset' => 'NOW()'
], 'users');
```

### **`increment_column()`**
Atomically increment a numeric column.

```php
public function increment_column(
    int $id,
    string $column,
    int|float $amount = 1,
    ?string $target_table = null
): bool
```

#### **Examples**
```php
// Increment view count by 1
$model->increment_column(123, 'view_count', 1, 'posts');

// Increase price by 5.50
$model->increment_column(456, 'price', 5.50, 'products');
```

### **`decrement_column()`**
Atomically decrement a numeric column.

```php
public function decrement_column(
    int $id,
    string $column,
    int|float $amount = 1,
    ?string $target_table = null
): bool
```

#### **Examples**
```php
// Decrease stock quantity by 1
$model->decrement_column(123, 'stock_quantity', 1, 'products');

// Reduce points by 50
$model->decrement_column(456, 'points', 50, 'users');
```

### **`touch_timestamp()`**
Update timestamp column to current database time.

```php
public function touch_timestamp(
    int $id,
    string $column = 'updated_at',
    ?string $target_table = null
): bool
```

#### **Examples**
```php
// Update last_login timestamp
$model->touch_timestamp(123, 'last_login', 'users');

// Update default updated_at column
$model->touch_timestamp(456, 'updated_at', 'posts');
```

---

## 🔄 **Transaction Management**

### **`transaction()`**
Safe transaction wrapper with nested transaction support.

```php
public function transaction(
    ?callable $callback = null, 
    array $options = []
): mixed
```

#### **Parameters**
- `$callback` - Optional callback function to execute within transaction
- `$options` - Transaction configuration options

#### **Returns**
- `mixed` - Result of callback function, or TransactionManager instance for manual use

#### **Examples**
```php
// Closure-based transaction (recommended)
$result = $model->transaction(function() use ($model) {
    $userId = $model->insert($userData, 'users');
    $model->insert(['user_id' => $userId, 'role' => 'admin'], 'user_roles');
    return $userId; // Automatically committed
});

// Manual transaction management
$tx = $model->transaction();
try {
    $userId = $model->insert($userData, 'users');
    $model->insert(['user_id' => $userId, 'role' => 'admin'], 'user_roles');
    $tx->commit();
} catch (Exception $e) {
    $tx->rollback();
    throw $e;
}

// Nested transactions with savepoints
$model->transaction(function() use ($model) {
    $model->insert($outerData, 'table1');
    
    $model->transaction(function() use ($model) {
        $model->insert($innerData, 'table2'); // Uses savepoint
    });
    
    $model->insert($moreData, 'table3');
});
```

### **`beginTransaction()`**, **`commit()`**, **`rollback()`**
Manual transaction control methods.

```php
public function beginTransaction(): bool
public function commit(): bool
public function rollback(): bool
```

---

## 🏭 **Factory Methods**

### **`backup()`**
Access database backup operations.

```php
public function backup(): DatabaseBackupFactory
```

#### **Examples**
```php
// Basic database backup
$result = $model->backup()->createBackup('/path/to/backup.sql');

// Advanced backup with options
$result = $model->backup()->createBackup('/path/to/backup.sql', [
    'compress' => true,
    'include_schema' => true,
    'timeout' => 1800
]);

// Restore from backup
$result = $model->backup()->restoreBackup('/path/to/backup.sql');

// Test backup capabilities
$capabilities = $model->backup()->testCapabilities();
```

### **`migration()`**
Access database migration operations.

```php
public function migration(): DatabaseMigrationFactory
```

#### **Examples**
```php
// Quick migration between databases
$migration = $devModel->migration();
$result = $migration->quickMigrate($devModel, $prodModel);

// Schema-only migration
$result = $migration->migrateSchemaOnly($sourceModel, $targetModel);

// Create migration validator
$validator = $migration->createValidator($sourceModel, $targetModel);
$compatibility = $validator->validateCompatibility();
```

### **`performance()`**
Access database performance operations.

```php
public function performance(): DatabasePerformance
```

#### **Examples**
```php
// Enable performance optimizations
$model->performance()->enablePerformanceMode();

// Get performance statistics
$stats = $model->performance()->getPerformanceStats();

// Configure adaptive thresholds
$perf = $model->performance();
$perf->setBulkThresholds(100, 20);
$perf->setAdaptiveMode(true);
```

### **`maintenance()`**
Access database maintenance operations.

```php
public function maintenance(): DatabaseMaintenance
```

#### **Examples**
```php
// Basic maintenance operations
$model->maintenance()->vacuum();           // Reclaim space
$model->maintenance()->analyze();          // Update statistics
$model->maintenance()->checkIntegrity();   // Verify integrity

// Comprehensive optimization
$model->maintenance()->optimize();         // Full maintenance
```

---

## 🐛 **Debug & Monitoring**

### **`setDebugPreset()`**
Set debug mode using predefined presets.

```php
public function setDebugPreset(string $preset): void
```

#### **Available Presets**
- `'off'` - Disable debugging completely
- `'basic'` - Basic SQL logging only
- `'developer'` - Detailed debugging with HTML output (recommended for development)
- `'performance'` - Performance monitoring and bulk operation analysis
- `'cli'` - CLI-friendly debugging with ANSI colors
- `'production'` - Production-safe performance monitoring with JSON output
- `'verbose'` - Maximum debugging with all categories and verbose output

#### **Examples**
```php
// Enable developer-friendly debugging
$model->setDebugPreset('developer');

// Enable CLI debugging for scripts
$model->setDebugPreset('cli');

// Production monitoring
$model->setDebugPreset('production');
```

### **`setDebug()`**
Advanced debug configuration.

```php
public function setDebug(
    DebugLevel|bool $level = false, 
    int|null $categories = null, 
    string $format = 'html'
): void
```

#### **Examples**
```php
// Fine-grained control
$model->setDebug(DebugLevel::DETAILED, DebugCategory::SQL | DebugCategory::PERFORMANCE, 'html');

// Legacy boolean support
$model->setDebug(true);  // Basic debugging with default settings
```

### **`getDebugOutput()`**
Get formatted debug output.

```php
public function getDebugOutput(): string
```

#### **Examples**
```php
// Execute queries with debugging enabled
$model->setDebugPreset('developer');
$users = $model->get_where_custom('status', 'active');

// Get rich HTML debug panel
echo $model->getDebugOutput();
```

### **`getDebugData()`**
Get raw debug data for programmatic access.

```php
public function getDebugData(): array
```

#### **Examples**
```php
// Get performance data for monitoring
$model->setDebugPreset('production');
$model->insert_batch('orders', $orderData);

$debugData = $model->getDebugData();
file_put_contents('/var/log/database_performance.json', json_encode($debugData));
```

### **`setDebugAutoOutput()`**
Enable or disable automatic debug output.

```php
public function setDebugAutoOutput(bool $enabled): void
```

#### **Examples**
```php
// Enable real-time debug output for CLI scripts
$model->setDebugPreset('cli');
$model->setDebugAutoOutput(true);
```

### **`exportDebugSession()`**
Export debug session for external analysis.

```php
public function exportDebugSession(string $format = 'json'): string
```

#### **Examples**
```php
// Export debug session in JSON format
$sessionData = $model->exportDebugSession('json');
file_put_contents('/logs/debug_session.json', $sessionData);
```

### **`clearDebugMessages()`**
Clear debug messages and reset counters.

```php
public function clearDebugMessages(): void
```

---

## 🛠️ **Utility & Helper Methods**

### **`enablePerformanceMode()`**
Enable performance mode with database optimizations.

```php
public function enablePerformanceMode(): void
```

### **`disablePerformanceMode()`**
Disable performance mode and restore normal settings.

```php
public function disablePerformanceMode(): void
```

### **`setAdaptiveMode()`**
Enable or disable adaptive mode for intelligent optimization.

```php
public function setAdaptiveMode(bool $enabled): void
```

### **`setBulkThresholds()`**
Manually configure bulk operation thresholds.

```php
public function setBulkThresholds(int $recordThreshold, int $operationThreshold): void
```

### **`getPerformanceStats()`**
Get comprehensive performance statistics.

```php
public function getPerformanceStats(): array
```

#### **Examples**
```php
$stats = $model->getPerformanceStats();
echo "Operations per second: " . $stats['performance']['operations_per_second'];
echo "Cache hit rate: " . $stats['cache']['hit_rate'];
```

### **`isDebugEnabled()`**
Check if debug mode is currently enabled.

```php
public function isDebugEnabled(): bool
```

### **`getDbType()`**
Get database type identifier.

```php
public function getDbType(): string
```

#### **Returns**
- `string` - Database type ('mysql', 'sqlite', 'postgresql')

---

## 🔍 **Database Introspection**

### **`table_exists()`**
Check if table exists in database.

```php
public function table_exists(string $table_name): bool
```

#### **Parameters**
- `$table_name` - Name of table to check for existence (validated)

#### **Returns**
- `bool` - True if table exists, false otherwise

#### **Examples**
```php
// Check if users table exists before creating
if (!$model->table_exists('users')) {
    $model->query("CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(255))");
}

// Conditional table operations
if ($model->table_exists('temp_data')) {
    $model->query("DROP TABLE temp_data");
}

// Check multiple tables
$requiredTables = ['users', 'products', 'orders'];
foreach ($requiredTables as $table) {
    if (!$model->table_exists($table)) {
        echo "Missing required table: $table\n";
    }
}
```

### **`get_all_tables()`**
Get all table names from the database.

```php
public function get_all_tables(): array
```

#### **Returns**
- `array` - Array of table names in the database

#### **Examples**
```php
// List all tables for backup
$tables = $model->get_all_tables();
foreach ($tables as $table) {
    echo "Backing up table: $table\n";
}

// Check if specific tables exist
$tables = $model->get_all_tables();
$requiredTables = ['users', 'products', 'orders'];
$missing = array_diff($requiredTables, $tables);
if (!empty($missing)) {
    echo "Missing tables: " . implode(', ', $missing) . "\n";
}

// Count total tables
$tableCount = count($model->get_all_tables());
echo "Database contains $tableCount tables\n";
```

### **`describe_table()`**
Describe table structure with cross-database compatibility.

```php
public function describe_table(
    string $table, 
    bool $column_names_only = false
): array|false
```

#### **Parameters**
- `$table` - Table name to describe (validated)
- `$column_names_only` - If true, return only column names array

#### **Returns**
- `array|false` - Column details array or column names, false on failure

#### **Examples**
```php
// Get full table structure
$structure = $model->describe_table('users');
if ($structure) {
    foreach ($structure as $column) {
        echo "Column: {$column['Field']}, Type: {$column['Type']}\n";
    }
}

// Get just column names
$columns = $model->describe_table('products', true);
// Returns: ['id', 'name', 'price', 'category_id', 'created_at']

// Check if specific column exists
$columns = $model->describe_table('users', true);
if (in_array('email', $columns)) {
    echo "Email column exists\n";
}

// Get column details for validation
$structure = $model->describe_table('orders');
foreach ($structure as $column) {
    if ($column['Field'] === 'total_amount') {
        echo "Total amount column type: " . $column['Type'] . "\n";
        echo "Nullable: " . $column['Null'] . "\n";
        echo "Default: " . $column['Default'] . "\n";
    }
}
```

### **`resequence_ids()`**
Resequence table IDs with cross-database compatibility and safety checks.

```php
public function resequence_ids(string $table_name): bool
```

#### **Parameters**
- `$table_name` - Table to resequence (validated)

#### **Returns**
- `bool` - True if resequencing succeeded, false otherwise

#### **⚠️ Warning**
This operation modifies primary keys and may affect referential integrity. Use with extreme caution and always backup data first.

#### **Examples**
```php
// Resequence user IDs after cleanup
$model->beginTransaction();
try {
    $success = $model->resequence_ids('users');
    if ($success) {
        $model->commit();
        echo "User IDs resequenced successfully";
    } else {
        $model->rollback();
        echo "Resequencing failed";
    }
} catch (Exception $e) {
    $model->rollback();
    error_log("Resequencing failed: " . $e->getMessage());
}

// Safe resequencing with backup
$backupFile = '/backups/before_resequence_' . date('Y_m_d_H_i_s') . '.sql';
$model->backup()->createBackup($backupFile);

if ($model->resequence_ids('products')) {
    echo "Product IDs resequenced successfully\n";
} else {
    echo "Resequencing failed - backup available at: $backupFile\n";
}

// Check if resequencing is needed
$maxId = $model->get_max('orders');
$count = $model->count('orders');
if ($maxId > $count * 1.5) {
    echo "Resequencing recommended - max ID: $maxId, count: $count\n";
    $model->resequence_ids('orders');
}
```

---

## 💾 **Raw SQL Methods**

### **`query()`**
Execute custom SQL query with optional result type specification.

```php
public function query(string $sql, ?string $return_type = null): mixed
```

#### **Parameters**
- `$sql` - SQL query string (no parameter binding - use `query_bind()` for parameters)
- `$return_type` - Result format ('object', 'array', or 'raw'), null for non-SELECT queries

#### **Returns**
- `mixed` - Query results or execution status depending on query type

#### **Examples**
```php
// Custom SELECT query (no parameters)
$results = $model->query(
    "SELECT u.name, COUNT(o.id) as order_count 
     FROM users u LEFT JOIN orders o ON u.id = o.user_id 
     GROUP BY u.id", 
    'object'
);

// Custom UPDATE query (no parameters)
$success = $model->query(
    "UPDATE products SET price = price * 1.1", 
    'raw'
);

// CREATE TABLE query
$model->query("CREATE INDEX idx_users_email ON users (email)");

// Note: For queries with parameters, use query_bind() instead
// $users = $model->query_bind(
//     "SELECT * FROM users WHERE status = :status", 
//     ['status' => 'active'], 
//     'object'
// );
```

### **`query_bind()`**
Execute parameterized SQL query with secure parameter binding.

```php
public function query_bind(
    string $sql, 
    array $data, 
    ?string $return_type = null
): array|object|null
```

#### **Parameters**
- `$sql` - SQL query with named placeholders (`:param_name`)
- `$data` - Associative array of parameter values to bind
- `$return_type` - Result format: 'object', 'array', or null for no results

#### **Returns**
- `array|object|null` - Query results in specified format, or null for non-SELECT queries

#### **Examples**
```php
// Safe parameterized query
$users = $model->query_bind(
    "SELECT * FROM users WHERE status = :status AND created_at > :date",
    ['status' => 'active', 'date' => '2024-01-01'],
    'object'
);

// Update with parameters
$model->query_bind(
    "UPDATE users SET last_login = :now WHERE id = :user_id",
    ['now' => date('Y-m-d H:i:s'), 'user_id' => 123]
);

// Complex query with multiple parameters
$analytics = $model->query_bind(
    "SELECT category_id, COUNT(*) as count, AVG(price) as avg_price 
     FROM products 
     WHERE created_at BETWEEN :start_date AND :end_date 
     AND status = :status 
     GROUP BY category_id 
     ORDER BY count DESC",
    [
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'active'
    ],
    'array'
);
```

---

## 📋 **Method Categories Summary**

| Category | Key Methods | Use Case |
|----------|-------------|----------|
| **Core CRUD** | `insert()`, `update()`, `delete()`, `get()` | Basic database operations |
| **Advanced Queries** | `get_where_custom()`, `get_where_in()`, `count_where()`, `get_max()` | Complex data retrieval |
| **Enhanced Aggregates** | `sum()`, `avg()`, `min()`, `max()` | Mathematical calculations with filters |
| **Existence & Validation** | `exists()`, `is_unique()` | Efficient existence checks and validation |
| **Enhanced Data Retrieval** | `get_first()`, `get_last()`, `get_random()`, `pluck()`, `get_distinct()` | Specialized data retrieval patterns |
| **Batch Operations** | `insert_batch()`, `update_batch()`, `delete_batch()` | High-performance bulk operations |
| **Atomic Operations** | `increment_column()`, `update_with_expressions()` | Race-condition-free operations |
| **Advanced CRUD** | `upsert()`, `toggle_column()` | Modern database operation patterns |
| **Pagination & Collections** | `paginate()`, `get_distinct()` | Data organization and presentation |
| **Time-Based Helpers** | `get_today()`, `get_recent()`, `get_by_date_range()` | Time-based data filtering |
| **Transactions** | `transaction()`, `beginTransaction()`, `commit()` | Data consistency |
| **Factories** | `backup()`, `migration()`, `performance()` | Advanced functionality |
| **Debug Tools** | `setDebugPreset()`, `getDebugOutput()` | Development and monitoring |
| **Utilities** | `enablePerformanceMode()`, `getPerformanceStats()` | Configuration and performance |
| **Introspection** | `table_exists()`, `get_all_tables()`, `describe_table()` | Database schema inspection |
| **Raw SQL** | `query()`, `query_bind()` | Custom operations |

---

## 🔗 **Related Documentation**

- **[Performance Optimization](PERFORMANCE.md)** - When and how to optimize
- **[Debug System](DEBUG-SYSTEM.md)** - Comprehensive debugging guide
- **[Expression Methods](EXPRESSION-METHODS.md)** - Atomic operations in detail
- **[Migration Guide](MIGRATION-GUIDE.md)** - Database migration workflows
- **[Backup System](BACKUP-SYSTEM.md)** - Backup and restore operations
- **[Database Support](DATABASE-SUPPORT.md)** - Multi-database compatibility

---

### **💡 Future Enhancements**

The following methods are planned for implementation with enhanced full-text search capabilities:

```php
// Advanced full-text search with database-specific optimizations
public function search(string $query, array $columns, ?string $target_table = null, array $options = []): array
// Will support:
// - MySQL: FULLTEXT indexes and MATCH() AGAINST() syntax
// - PostgreSQL: to_tsvector() and to_tsquery() for advanced text search
// - SQLite: FTS5 virtual tables for efficient search
// - Cross-database compatibility with automatic fallback to LIKE patterns
```

Additional convenience methods that could be implemented based on common patterns:

```php
// Relationship helpers for simple associations
public function has_related(int $id, string $related_table, string $foreign_key): bool
public function count_related(int $id, string $related_table, string $foreign_key): int

// Soft delete pattern helpers
public function soft_delete(int $id, string $deleted_column = 'deleted_at'): bool
public function restore(int $id, string $deleted_column = 'deleted_at'): bool

// Find duplicate records helper
public function find_duplicates(string $column, ?string $target_table = null): array
```

## 💡 **Best Practices**

### **✅ Method Selection Guidelines**

1. **Use appropriate CRUD methods** for single-record operations
2. **Use batch methods** for multiple records (50+ records)
3. **Use expression methods** for atomic updates (counters, timestamps)
4. **Use transactions** for multi-step operations requiring consistency
5. **Use factory methods** for advanced functionality (backup, migration, debug)
6. **Use introspection methods** for dynamic schema operations and validation
7. **Use aggregate functions** instead of custom calculations (`sum()`, `avg()`, etc.)
8. **Use existence helpers** (`exists()`, `is_unique()`) instead of counting for validation
9. **Use convenience retrievers** (`get_first()`, `get_last()`, `pluck()`) for common patterns
10. **Use pagination helpers** for user interfaces requiring paged data
11. **Use debug presets** appropriate for your environment

### **🔒 Security Considerations**

1. **Always use parameter binding** for user input
2. **Use `allowed_columns`** parameter in expression methods
3. **Validate table and column names** (handled automatically by QueryBuilder)
4. **Use transactions** for operations requiring atomicity
5. **Enable debug mode only in development** environments
6. **Use `table_exists()` before dynamic table operations** to prevent errors
7. **Use `is_unique()` for validation** instead of manual existence checking

### **⚡ Performance Tips**

1. **Enable performance mode** for bulk operations
2. **Use appropriate chunk sizes** for large datasets
3. **Use `get_one_where()` instead of `get_where_custom()`** for single records
4. **Use batch operations** instead of loops for multiple records
5. **Monitor performance** with debug tools and optimize based on recommendations
6. **Cache introspection results** when checking multiple tables repeatedly
7. **Use `exists()` instead of `count() > 0`** for large tables (significantly faster)
8. **Use aggregate functions** (`sum()`, `avg()`) instead of fetching all records
9. **Use `pluck()` for single columns** instead of fetching full records
10. **Use pagination** for large result sets in user interfaces

### **🔧 Convenience Method Best Practices**

1. **Aggregate Functions** - Use filtered aggregates (`sum()` with WHERE) instead of fetching and calculating
2. **Existence Checks** - Always use `exists()` for large tables, `is_unique()` for validation
3. **Data Retrieval** - Use `get_first()`, `get_last()` for ordered single records
4. **Collection Operations** - Use `pluck()` for arrays, `get_distinct()` for unique values
5. **Time-Based Queries** - Use `get_recent()`, `get_today()` instead of manual date ranges
6. **CRUD Operations** - Use `upsert()` for insert-or-update patterns, `toggle_column()` for booleans
7. **Pagination** - Always use built-in `paginate()` for consistent metadata and performance

This API reference provides complete documentation for TronBridge's enhanced Model class with **100+ public methods** across 16 functional categories. The extensive collection of convenience methods dramatically reduces the need for custom SQL while maintaining TronBridge's security, performance, and cross-database compatibility standards. Each method includes detailed parameter information, return values, and practical examples to help you build efficient, maintainable database applications.