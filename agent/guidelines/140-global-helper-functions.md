## Global Helper Functions

Available in all views via `lib/functions.php`:

```php
// CSRF protection in forms
<?= csrf_field() ?>     // Outputs: <input type="hidden" name="_csrf_token" value="...">
<?= csrf_token() ?>     // Returns just the token value (for AJAX X-CSRF-TOKEN header)
```
