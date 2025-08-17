# Atomic Operations & Expression Methods
> Race-condition-free database operations with cross-database function translation

TronBridge provides **atomic database operations** through expression methods that eliminate race conditions, provide cross-database function translation, and maintain security through comprehensive validation.

## 🎯 **Key Features**

- **⚡ Atomic Operations** - Eliminate race conditions with database-level calculations
- **🔒 Security First** - Comprehensive validation with focused, safe patterns
- **🌐 Cross-Database Functions** - Automatic bidirectional translation for MySQL, SQLite, PostgreSQL
- **🚀 Performance Optimized** - Intelligent caching with minimal overhead
- **🎨 Developer Friendly** - Convenient helper methods for common patterns
- **🔍 Debug Integrated** - Full integration with TronBridge's debug system

---

## ⚡ **Quick Start**

### **Atomic Counter Updates**
```php
// Race-condition-free counter increment
$model->increment_column(123, 'view_count', 1, 'posts');

// Multiple atomic updates
$model->update_with_expressions($userId, [], [
    'login_count' => 'login_count + 1',
    'last_login' => 'NOW()'
], 'users', ['login_count']);
```

### **Cross-Database Function Translation**
```php
// This works on ALL databases - TronBridge translates automatically
$model->update_with_expressions($postId, [], [
    'published_at' => 'NOW()',           // MySQL: NOW(), SQLite: datetime('now'), PostgreSQL: NOW()
    'random_sort' => 'RANDOM()',         // MySQL: RAND(), SQLite: RANDOM(), PostgreSQL: RANDOM()
    'title_upper' => 'UPPER(title)'      // Works on all databases
], 'posts', ['title']);
```

### **Safe Mixed Operations**
```php
// Combine parameter binding (safe) with expressions (validated)
$model->update_with_expressions($productId, 
    ['description' => $userInput],       // Parameter binding - always safe
    ['view_count' => 'view_count + 1'],  // Expression - validated
    'products', 
    ['view_count']                       // Explicit column allowlist
);
```

---

## 🔧 **Core Expression Methods**

### **`update_with_expressions()`**
Update record with atomic expression support.

```php
public function update_with_expressions(
    int $update_id, 
    array $data = [], 
    array $expressions = [], 
    ?string $target_table = null,
    array $allowed_columns = []
): bool
```

#### **Parameters**
- `$update_id` - Record ID to update
- `$data` - Standard column => value pairs for parameter binding  
- `$expressions` - Column => expression pairs for literal SQL
- `$target_table` - Target table name (optional)
- `$allowed_columns` - Columns that can be referenced in expressions

#### **Examples**
```php
// Simple atomic counter
$model->update_with_expressions(123, [], [
    'counter' => 'counter + 1',
    'last_updated' => 'NOW()'
], 'stats', ['counter']);

// Mixed parameter and expression update
$model->update_with_expressions(456, 
    ['name' => 'Updated Name'],              // Parameter binding
    ['view_count' => 'view_count + 1'],      // Expression
    'posts', 
    ['view_count']                           // Allow view_count in expressions
);

// Conditional updates with CASE
$model->update_with_expressions(789, [], [
    'status' => "CASE WHEN views > 100 THEN 'popular' ELSE 'normal' END",
    'category_score' => 'views + likes'
], 'posts', ['views', 'likes']);
```

### **`insert_with_expressions()`**
Insert record with computed values using expressions.

```php
public function insert_with_expressions(
    array $data = [],
    array $expressions = [],
    ?string $target_table = null,
    array $allowed_columns = []
): int|false
```

#### **Examples**
```php
// Insert with computed timestamp
$id = $model->insert_with_expressions([
    'name' => 'New Post',
    'content' => 'Post content'
], [
    'created_at' => 'NOW()',
    'initial_score' => '0'
], 'posts');

// Insert with cross-database calculations
$id = $model->insert_with_expressions([
    'user_id' => $userId,
    'action' => 'login'
], [
    'timestamp' => 'CURRENT_TIMESTAMP',      // PostgreSQL-style
    'random_id' => 'RANDOM()',               // Works on all databases
    'day_of_week' => "CASE WHEN CURRENT_TIME < '12:00:00' THEN 'morning' ELSE 'afternoon' END"
], 'user_activity');
```

### **`update_where_with_expressions()`**
Update multiple records matching condition with expression support.

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

// Mixed parameter and expression bulk update
$model->update_where_with_expressions('category_id', 3, 
    ['updated_by' => 'admin'],               // Parameter binding
    ['price' => 'price * 1.1'],              // Expression
    'products',
    ['price']                                // Allow price in expressions
);
```

---

## 🎯 **Convenience Helper Methods**

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

// Increment with default amount (1)
$model->increment_column(789, 'like_count', 1, 'comments');
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

// Touch with default column name
$model->touch_timestamp(789, null, 'sessions'); // Uses 'updated_at'
```

---

## 🌐 **Cross-Database Function Translation**

TronBridge automatically translates functions between database types, supporting both **MySQL-style** and **PostgreSQL-style** function names.

### **Bidirectional Function Support**

You can use **either style** - TronBridge translates automatically:

```php
// MySQL-style functions (traditional)
$model->update_with_expressions($id, [], [
    'last_updated' => 'NOW()',
    'date_created' => 'CURDATE()',
    'time_stamp' => 'CURTIME()'
], 'posts');

// PostgreSQL-style functions (SQL standard)
$model->update_with_expressions($id, [], [
    'last_updated' => 'CURRENT_TIMESTAMP', 
    'date_created' => 'CURRENT_DATE',
    'time_stamp' => 'CURRENT_TIME'
], 'posts');

// Mixed style (also works)
$model->update_with_expressions($id, [], [
    'mysql_style' => 'NOW()',
    'postgres_style' => 'CURRENT_DATE'
], 'posts');
```

### **Function Translation Table**

#### **Date/Time Functions**
| Expression | MySQL | SQLite | PostgreSQL |
|------------|-------|--------|------------|
| `NOW()` | `NOW()` | `datetime('now')` | `NOW()` |
| `CURRENT_TIMESTAMP` | `NOW()` | `datetime('now')` | `CURRENT_TIMESTAMP` |
| `CURDATE()` | `CURDATE()` | `date('now')` | `CURRENT_DATE` |
| `CURRENT_DATE` | `CURDATE()` | `date('now')` | `CURRENT_DATE` |
| `CURTIME()` | `CURTIME()` | `time('now')` | `CURRENT_TIME` |
| `CURRENT_TIME` | `CURTIME()` | `time('now')` | `CURRENT_TIME` |

#### **String Functions**
| Expression | MySQL | SQLite | PostgreSQL |
|------------|-------|--------|------------|
| `UPPER()` | `UPPER()` | `UPPER()` | `UPPER()` |
| `LOWER()` | `LOWER()` | `LOWER()` | `LOWER()` |
| `LENGTH()` | `LENGTH()` | `LENGTH()` | `LENGTH()` |
| `TRIM()` | `TRIM()` | `TRIM()` | `TRIM()` |
| `SUBSTRING()` | `SUBSTRING()` | `SUBSTR()` | `SUBSTRING()` |
| `SUBSTR()` | `SUBSTRING()` | `SUBSTR()` | `SUBSTRING()` |

#### **Mathematical Functions**
| Expression | MySQL | SQLite | PostgreSQL |
|------------|-------|--------|------------|
| `RAND()` | `RAND()` | `RANDOM()` | `RANDOM()` |
| `RANDOM()` | `RAND()` | `RANDOM()` | `RANDOM()` |
| `ABS()` | `ABS()` | `ABS()` | `ABS()` |
| `ROUND()` | `ROUND()` | `ROUND()` | `ROUND()` |
| `FLOOR()` | `FLOOR()` | `ROUND(x-0.5)` | `FLOOR()` |

### **Translation Examples**
```php
// This single expression set works on ALL databases
$model->update_with_expressions($logId, [], [
    'mysql_style' => 'RAND()',           // AUTO-TRANSLATED: MySQL: RAND(), SQLite: RANDOM(), PostgreSQL: RANDOM()
    'postgres_style' => 'RANDOM()',      // AUTO-TRANSLATED: MySQL: RAND(), SQLite: RANDOM(), PostgreSQL: RANDOM()
    'sqlite_style' => 'SUBSTR(data, 1, 10)',  // AUTO-TRANSLATED: MySQL: SUBSTRING(), SQLite: SUBSTR(), PostgreSQL: SUBSTRING()
    'universal' => 'ABS(score)',         // NO TRANSLATION NEEDED: Works on all databases
    'mixed_functions' => 'UPPER(TRIM(name))'  // MULTIPLE TRANSLATIONS: All functions translated appropriately
], 'logs', ['data', 'score', 'name']);
```

---

## 🔒 **Security Model**

### **Two-Layer Security Architecture**

1. **DatabaseSecurity**: Pure security validation (database-agnostic)
2. **QueryBuilder**: Database-specific translation

#### **Security Flow**
```php
// Step 1: Security validation (DatabaseSecurity)
$validatedExpression = DatabaseSecurity::validateExpression(
    'CURRENT_DATE',  // PostgreSQL-style function
    'update_set', 
    []  // no column references needed for function
);
// → Returns: 'CURRENT_DATE' (validated, generic)

// Step 2: Database translation (QueryBuilder)  
$translatedExpression = $queryBuilder->translateExpression($validatedExpression);
// → MySQL: 'CURDATE()', SQLite: "date('now')", PostgreSQL: 'CURRENT_DATE'
```

### **Expression Safety Levels**

#### **Level 1: Arithmetic Expressions** (Safest)
```php
// Pattern: column_name [operator] [number|column_name]
'counter + 1'           // ✅ Safe
'price * 0.9'           // ✅ Safe  
'quantity - stock_used' // ✅ Safe (if both columns allowed)
```

#### **Level 2: Simple Function Calls** (Controlled)
```php
// Date/time functions (auto-translated):
'NOW()'         // ✅ Safe - works on all databases
'CURDATE()'     // ✅ Safe - works on all databases  
'CURRENT_DATE'  // ✅ Safe - works on all databases

// String functions (auto-translated):
'UPPER()'       // ✅ Safe - universal
'LOWER()'       // ✅ Safe - universal
'LENGTH()'      // ✅ Safe - universal

// Mathematical functions (auto-translated):
'RAND()'        // ✅ Safe - works on all databases
'ABS()'         // ✅ Safe - universal
'ROUND()'       // ✅ Safe - universal
```

#### **Level 3: Simple CASE Expressions** (Advanced)
```php
// Simple CASE expressions with validation
"CASE WHEN views > 100 THEN 'popular' ELSE 'normal' END"  // ✅ Safe (if 'views' allowed)
```

### **Security Rules**

#### **✅ SAFE Patterns**
```php
// Pre-validated expressions with explicit column allowlist
$model->update_with_expressions(123, [], [
    'price' => 'price * 1.1',        // Arithmetic with allowed column
    'updated_at' => 'NOW()'           // Whitelisted function
], 'products', ['price']);           // Explicit allowlist

// User input in parameter binding (not expressions)
$userInput = $_POST['description'];
$model->update_with_expressions(123, [
    'description' => $userInput       // Parameter binding - always safe
], [
    'last_updated' => 'NOW()'         // Expression - validated
], 'products');
```

#### **❌ UNSAFE Patterns**
```php
// NEVER put user input in expressions
$userInput = $_POST['calculation'];  // Could be: "'; DROP TABLE products; --"
$model->update_with_expressions(123, [], [
    'result' => $userInput           // ❌ Would fail validation
], 'products');

// References to non-allowed columns
$model->update_with_expressions(123, [], [
    'total' => 'price * quantity'    // ❌ Would fail if 'price' not in allowed_columns
], 'products', ['quantity']);        // 'price' not allowed
```

---

## ⚡ **Performance Characteristics**

### **Auto-Bulk Detection Control**

**Key Feature**: Expression methods **disable auto-bulk detection** to ensure predictable, atomic behavior.

```php
// This loop will NOT trigger auto-bulk detection
for ($i = 1; $i <= 15; $i++) {
    $model->update_with_expressions($i, [], [
        'counter' => 'counter + 1',
        'last_access' => 'NOW()'
    ], 'user_stats', ['counter']);
}
// Each operation is processed individually with predictable behavior
```

**Why Auto-Bulk is Disabled:**
- **Heterogeneous expressions**: Each operation might have different expressions
- **Security complexity**: Different `allowed_columns` per operation
- **Predictable behavior**: Developers know exactly what's happening
- **Atomic operations**: Each expression update is individual and atomic

### **Performance Overhead**

| Operation Type | Overhead | Use Case |
|---------------|----------|----------|
| **No expressions** | 0% | Standard operations unchanged |
| **Simple arithmetic** | 1-3% | Basic counter increments |
| **Function calls** | 2-5% | Timestamp updates |
| **Simple CASE expressions** | 3-7% | Basic conditional logic |

### **Cross-Database Translation Caching**

Expressions automatically translate across database types with **zero overhead for repeated expressions** due to intelligent caching.

```php
// First usage: Small translation overhead
$model->update_with_expressions($id, [], ['updated_at' => 'NOW()'], 'posts');

// Subsequent usages: Zero translation overhead (cached)
$model->update_with_expressions($id2, [], ['updated_at' => 'NOW()'], 'posts');
$model->update_with_expressions($id3, [], ['updated_at' => 'NOW()'], 'posts');
```

---

## 🚀 **Real-World Usage Patterns**

### **E-commerce Applications**

#### **Product Management**
```php
// Product view tracking (race-condition free)
$model->update_with_expressions($productId, [], [
    'view_count' => 'view_count + 1',
    'last_viewed' => 'NOW()'
], 'products', ['view_count']);

// Inventory management with atomic stock updates
$model->update_with_expressions($productId, [], [
    'stock_quantity' => 'stock_quantity - 1',
    'updated_at' => 'CURRENT_TIMESTAMP'      // PostgreSQL-style works everywhere
], 'products', ['stock_quantity']);

// Dynamic pricing with conditional logic
$model->update_with_expressions($productId, [], [
    'price_tier' => "CASE WHEN price > 100 THEN 'premium' WHEN price > 50 THEN 'standard' ELSE 'budget' END",
    'discount_eligible' => "CASE WHEN view_count > 1000 THEN 1 ELSE 0 END"
], 'products', ['price', 'view_count']);
```

#### **User Activity Tracking**
```php
// Login tracking with cross-database compatibility
$model->update_with_expressions($userId, [], [
    'login_count' => 'login_count + 1',
    'last_login' => 'NOW()',                 // MySQL-style
    'session_start' => 'CURRENT_TIMESTAMP'   // PostgreSQL-style (both work!)
], 'users', ['login_count']);

// User engagement scoring
$model->update_with_expressions($userId, [], [
    'engagement_score' => 'posts_count + comments_count * 2',
    'activity_level' => "CASE WHEN login_count > 100 THEN 'high' WHEN login_count > 10 THEN 'medium' ELSE 'low' END"
], 'user_stats', ['posts_count', 'comments_count', 'login_count']);
```

#### **Order Processing**
```php
// Order status updates with timestamps
$model->update_with_expressions($orderId, [], [
    'status' => "'shipped'",        // Literal string value
    'shipped_at' => 'NOW()',
    'estimated_delivery' => "date('now', '+3 days')"  // SQLite-style (auto-translated)
], 'orders');

// Inventory updates when order ships
$model->update_where_with_expressions('order_id', $orderId, [], [
    'stock_quantity' => 'stock_quantity - ordered_quantity',
    'last_sale' => 'NOW()'
], 'products', ['stock_quantity', 'ordered_quantity']);
```

### **Analytics and Reporting**

#### **Page Analytics**
```php
// Page view analytics with function style flexibility
$model->update_with_expressions($pageId, [], [
    'view_count' => 'view_count + 1',
    'last_viewed' => 'NOW()',                    // MySQL-style
    'date_analyzed' => 'CURRENT_DATE',           // PostgreSQL-style  
    'popularity_score' => 'view_count * 0.1'    // Calculated field
], 'pages', ['view_count']);

// Session tracking with mixed database functions
$model->insert_with_expressions([
    'user_id' => $userId,
    'page_id' => $pageId
], [
    'session_start' => 'CURRENT_TIMESTAMP',      // SQL standard
    'random_id' => 'RANDOM()',                   // Works on all databases
    'day_name' => "CASE WHEN strftime('%w', 'now') = '0' THEN 'Sunday' ELSE 'Weekday' END"  // SQLite-style
], 'page_sessions');
```

#### **Content Management**
```php
// Article publishing with computed fields
$model->update_with_expressions($articleId, [], [
    'status' => "'published'",
    'published_at' => 'NOW()',
    'word_count' => 'LENGTH(content) / 5',      // Rough word count estimate
    'reading_time' => 'LENGTH(content) / 200'   // Estimated reading time in minutes
], 'articles', ['content']);

// Comment moderation with automatic scoring
$model->insert_with_expressions([
    'article_id' => $articleId,
    'user_id' => $userId,
    'content' => $commentContent
], [
    'created_at' => 'NOW()',
    'spam_score' => 'CASE WHEN LENGTH(content) < 10 THEN 5 ELSE 0 END',
    'auto_approved' => 'CASE WHEN LENGTH(content) > 50 THEN 1 ELSE 0 END'
], 'comments', ['content']);
```

### **Financial Applications**

#### **Transaction Processing**
```php
// Account balance updates (atomic to prevent race conditions)
$model->update_with_expressions($accountId, [], [
    'balance' => 'balance + 100.00',
    'last_transaction' => 'NOW()',
    'transaction_count' => 'transaction_count + 1'
], 'accounts', ['balance', 'transaction_count']);

// Interest calculation
$model->update_where_with_expressions('account_type', 'savings', [], [
    'balance' => 'balance * 1.0025',           // 0.25% monthly interest
    'interest_updated' => 'NOW()'
], 'accounts', ['balance']);
```

---

## 🔄 **Integration with Other TronBridge Features**

### **Combining with Transactions**
```php
$model->beginTransaction();

try {
    // Standard operations
    $orderId = $model->insert($orderData, 'orders');
    
    // Expression operations (atomic within transaction)
    $model->decrement_column($productId, 'stock_quantity', $quantity, 'products');
    $model->increment_column($userId, 'order_count', 1, 'users');
    $model->touch_timestamp($userId, 'last_order_at', 'users');
    
    $model->commit();
} catch (Exception $e) {
    $model->rollback();
    throw $e;
}
```

### **Combining with Debug System**
```php
// Enable debugging to see expression translation
$model->setDebugPreset('developer');

$model->update_with_expressions($id, [], [
    'counter' => 'counter + 1',
    'updated_at' => 'NOW()'
], 'stats', ['counter']);

echo $model->getDebugOutput();
// Shows:
// - Expression validation process
// - Cross-database function translation
// - Generated SQL for your database type
// - Performance analysis
```

### **Combining with Database Maintenance**
```php
// Clear prepared statement cache when needed
$maintenance = $model->maintenance();
$maintenance->deallocate_all();

// Use expressions after cache clearing
$model->update_with_expressions($id, [], ['counter' => 'counter + 1'], 'stats', ['counter']);
```

---

## 🎯 **When to Use Expression Methods vs Raw SQL**

### **✅ Use Expression Methods When:**
- **Simple arithmetic**: `counter + 1`, `price * 0.9`
- **Basic functions**: `NOW()`, `UPPER()`, `RANDOM()`
- **Simple CASE statements**: Basic conditional logic
- **Cross-database compatibility needed**: Want same code to work on all databases
- **Security is paramount**: Need validated, safe expressions

### **✅ Use Raw SQL When:**
- **Complex nested functions**: `COALESCE(NULLIF())`, `SUBSTRING(CONCAT())`
- **Advanced CASE statements**: Multiple conditions with complex logic
- **Database-specific optimizations**: Want to leverage unique database features
- **Complex business logic**: When expressions become hard to read

### **Examples of When to Use Raw SQL**
```php
// ❌ DON'T try to use complex nested functions in expressions
// $model->update_with_expressions($id, [], [
//     'status' => "COALESCE(NULLIF(status, ''), 'default')"  // Too complex!
// ], 'posts', ['status']);

// ✅ DO use raw SQL for complex logic
$model->query("UPDATE posts SET status = COALESCE(NULLIF(status, ''), 'default') WHERE id = ?", [$id]);

// ✅ DO use raw SQL for complex CASE statements
$model->query("
    UPDATE posts SET 
        priority = CASE 
            WHEN views > 1000 AND comments > 50 THEN 'high'
            WHEN views > 500 OR comments > 25 THEN 'medium'
            ELSE 'low'
        END,
        status = COALESCE(NULLIF(TRIM(status), ''), 'draft')
    WHERE category_id = ?
", [$categoryId]);
```

---

## 📋 **Best Practices**

### **1. Security First**
```php
// ✅ ALWAYS use allowed_columns when column references are needed
$model->update_with_expressions($id, [], [
    'total' => 'price * quantity'
], 'products', ['price', 'quantity']);  // Explicit allowlist

// ✅ NEVER put user input in expressions
$userPrice = $_POST['price'];  // User input
$model->update_with_expressions($id, [
    'price' => $userPrice      // Parameter binding - safe
], [
    'updated_at' => 'NOW()'    // Expression - validated
], 'products');
```

### **2. Performance Optimization**
```php
// ✅ Use convenience methods for simple patterns
$model->increment_column(123, 'counter', 1, 'stats');
$model->touch_timestamp(456, 'last_updated', 'posts');

// ✅ Leverage database-native functions instead of PHP
$model->update_with_expressions($id, [], [
    'created_at' => 'NOW()'    // Database timestamp (faster, more accurate)
], 'posts');
// Instead of: ['created_at' => date('Y-m-d H:i:s')]  // PHP timestamp
```

### **3. Cross-Database Compatibility**
```php
// ✅ Use either MySQL or PostgreSQL style - both work
$model->update_with_expressions($id, [], [
    'mysql_style' => 'NOW()',
    'postgres_style' => 'CURRENT_TIMESTAMP'
], 'logs');

// ✅ Test on all target database types
// ✅ Use debug output to verify translation behavior
```

### **4. Function Style Consistency**
```php
// ✅ Choose a consistent style for your project
// Option 1: MySQL-style throughout
$model->update_with_expressions($id, [], [
    'timestamp' => 'NOW()',
    'date_only' => 'CURDATE()',
    'random_val' => 'RAND()'
], 'table');

// Option 2: PostgreSQL-style throughout  
$model->update_with_expressions($id, [], [
    'timestamp' => 'CURRENT_TIMESTAMP',
    'date_only' => 'CURRENT_DATE',
    'random_val' => 'RANDOM()'
], 'table');

// Both work equally well - pick what your team prefers
```

### **5. Testing and Validation**
```php
// ✅ Test expressions on all target database types
$databases = [
    'sqlite' => 'sqlite::memory:',
    'mysql' => 'mysql:host=localhost;dbname=test',
    'postgresql' => 'postgresql:host=localhost;dbname=test'
];

foreach ($databases as $type => $connection) {
    $model = new Model('test', $connection);
    $model->setDebugPreset('cli');
    
    $result = $model->update_with_expressions(1, [], [
        'counter' => 'counter + 1',
        'updated_at' => 'NOW()'
    ], 'test_table', ['counter']);
    
    echo "$type: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
}
```

---

## 📊 **Summary**

TronBridge's expression system provides:

- **✅ Atomic Operations** - Eliminate race conditions with database-level calculations  
- **✅ Security First** - Comprehensive validation with focused, safe patterns  
- **✅ Cross-Database Functions** - Automatic bidirectional translation for MySQL, SQLite, PostgreSQL  
- **✅ Function Flexibility** - Support both MySQL-style and PostgreSQL-style functions  
- **✅ Performance Optimized** - Intelligent caching with minimal overhead  
- **✅ Debug Integrated** - Full integration with TronBridge's debug system  
- **✅ Developer Friendly** - Convenient helper methods for common patterns  
- **✅ Focused Scope** - Handles 90% of use cases while directing complex logic to raw SQL

**The expression methods enable powerful, secure, and performant atomic database operations while maintaining a clear boundary between simple expressions and complex SQL logic.**