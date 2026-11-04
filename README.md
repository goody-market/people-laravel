# People for Laravel

A guard that verifies People's tokens, the SDK wired into the container, a command
that keeps identities current, and a fake for your tests.

Built on [`oki-technologies/people-php`](../people-php) — read its README for what People
is and what each call checks.

```bash
composer require oki-technologies/people-laravel
php artisan vendor:publish --tag=people-config
```

```dotenv
PEOPLE_ISSUER=https://people.example.com
PEOPLE_CLIENT_ID=your-client-id
PEOPLE_CLIENT_SECRET=your-secret     # server-side applications only
PEOPLE_AUDIENCE=api.example.com      # defaults to APP_URL's hostname
```

## Protecting an API

```php
// config/auth.php
'guards' => [
    'people' => ['driver' => 'people'],
],
```

```php
Route::middleware('auth:people')->get('/orders', function (Request $request) {
    $request->user()->getAuthIdentifier(); // People's user id
    Auth::guard('people')->token()->can('orders.read');
});
```

Every request is verified locally — no session, no call to People. By default the user
is a `PeopleUser` carrying the verified token. To use your own model:

```php
PeopleGuard::resolveUsersUsing(fn (AccessToken $token) => User::firstWhere('people_id', $token->subject));
```

Returning null turns the request away. A token proves who is calling, never what they
may do: decide that from your own records.

## Signing somebody in

```php
$request = app(People::class)->authorization()->begin(route('people.callback'));
session(['people' => $request]);

return redirect($request->url);
```

```php
$tokens = app(People::class)->authorization()->exchange($request->query(), session()->pull('people'));
```

## Keeping identities current

The package keeps a projection for you: `identity_profiles`, a read model of the people
your application has met, fed by People's events. Migrate, and it is there.

```php
use Oki\People\Laravel\Identity\IdentityProfile;

IdentityProfile::query()->for($token->subject)->first()?->display_name;
```

```php
Schedule::command('people:sync')->everyMinute()->withoutOverlapping();
```

`people.events.handler` is `ProjectsIdentities` out of the box, and the cursor is the
highest sequence it has applied — there is nothing to keep yourself.

**Somebody first sight has never heard of must not wait for an event**, so project the
one profile read that met them:

```php
app(ProjectsIdentities::class)->fromProfile($token->subject, $profile);
```

**A projection is a read model, never an authority.** Draw screens from it; ask People
about anything that turns on whether somebody may still act — approving a payment,
acting as somebody else — or refuse.

### Being posted to instead of polling

Register an address at People (`php artisan webhook:register {client} {url}` there), put
the secret it prints in `PEOPLE_WEBHOOK_SECRET`, and point a route at the receiver:

```php
use Oki\People\Laravel\Http\IdentityWebhookController;

Route::post('webhooks/identity', IdentityWebhookController::class);
```

It checks the signature before reading the body, applies the event, and answers `204`.
Deliveries are at least once, so the same event arrives twice and is applied once.
`VerifiesIdentityWebhook::for()` makes a signature, for your own tests.

Keep `people:sync` scheduled either way: it is how a receiver that was down catches up,
and it costs nothing when there is nothing to apply.

### Applying events yourself

Where the projection is not the shape you want, implement `HandlesIdentityEvents` and
keep the cursor somewhere durable:

```php
final class ApplyIdentityEvents implements HandlesIdentityEvents
{
    public function cursor(): int { return (int) Setting::get('people.cursor', 0); }

    public function handle(IdentityEvent $event): void
    {
        match ($event->type) {
            IdentityEvent::UPDATED => User::where('people_id', $event->subject)->update(['name' => $event->payload['name'] ?? null]),
            IdentityEvent::DELETED => User::where('people_id', $event->subject)->delete(),
            default => null,
        };
    }

    public function advance(int $sequence): void { Setting::set('people.cursor', $sequence); }
}
```

```php
// config/people.php
'events' => ['handler' => ApplyIdentityEvents::class],
```

```php
Schedule::command('people:sync')->everyMinute()->withoutOverlapping();
```

You hear only about people who signed in to your application. Applying an event must be
safe to repeat.

## Testing

```php
$people = FakePeople::install();

$this->withToken($people->token('user-1', ['scopes' => ['orders.read']]))
    ->getJson('/orders')
    ->assertOk();
```

Nothing goes over the network, and `Http::fake()` never sees People's calls.

## Licence

MIT.
