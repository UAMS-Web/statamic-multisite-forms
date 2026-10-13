<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use Statamic\Facades\Form;
use Statamic\Facades\Stache;
use Statamic\Forms\Email;
use Tests\TestCase;

/**
 * Host-bound: a site's `site_overrides` row reaches core's submit path, in a real
 * two-site host (#75).
 *
 * One form is shared by `en` and `es`, emails `base@example.com` and stores its
 * submissions; its `es` row emails `es@example.com` and stores nothing. A submit
 * from each site is read the way spike 5 read it: the email config core hands to
 * each mailable, which the faked mailer records before the mailable builds, and
 * the number of stored submissions. Form files and submissions go to a temporary
 * directory, so the host's own content is never written.
 *
 * @command  php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/SiteOverridesTest.php
 */
final class SiteOverridesTest extends TestCase
{
    private const string ACTION = '/!/forms/msf_host_overrides';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/msf-host-overrides-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);
        Stache::store('form-submissions')->directory($this->directory.'/_submissions');

        Form::make('msf_host_overrides')->title('Overrides')->store(true)
            ->email([['to' => 'base@example.com', 'subject' => 'Base']])
            ->set('sites', ['en', 'es'])
            ->set('site_overrides', [[
                'site' => 'es',
                'email' => [['to' => 'es@example.com', 'subject' => 'ES']],
                'store' => false,
            ]])
            ->save();

        Mail::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** A submit from `en`, which has no row, emails the base recipient and is stored. */
    public function test_a_submit_from_a_site_without_a_row_uses_the_base_values(): void
    {
        $this->post(self::ACTION, ['message' => 'en'], ['referer' => url('/contact')])->assertRedirect();

        $this->assertSame(['base@example.com'], $this->recipients());
        $this->assertSame(1, Form::find('msf_host_overrides')->submissions()->count());
    }

    /** A submit from `es` emails only the row's recipient and is not stored. */
    public function test_a_submit_from_a_site_with_a_row_uses_its_overrides(): void
    {
        $this->post(self::ACTION, ['message' => 'es'], ['referer' => url('/es/contacto')])->assertRedirect();

        $this->assertSame(['es@example.com'], $this->recipients());
        $this->assertSame(0, Form::find('msf_host_overrides')->submissions()->count());
    }

    /** The two sites together: one recipient each, one stored submission, and the form file unchanged. */
    public function test_each_site_emails_its_own_recipient_and_only_en_stores(): void
    {
        $this->post(self::ACTION, ['message' => 'en'], ['referer' => url('/contact')])->assertRedirect();
        $this->post(self::ACTION, ['message' => 'es'], ['referer' => url('/es/contacto')])->assertRedirect();

        $this->assertSame(['base@example.com', 'es@example.com'], $this->recipients());

        $form = Form::find('msf_host_overrides');
        $this->assertSame(1, $form->submissions()->count());
        $this->assertSame('en', $form->submissions()->first()?->get('_site'));
        $this->assertSame([['to' => 'base@example.com', 'subject' => 'Base']], $form->email());
        $this->assertTrue($form->store());
    }

    /**
     * The `to` of each email config core handed to a mailable, sent or queued, in order.
     *
     * @return list<string>
     */
    private function recipients(): array
    {
        $config = new ReflectionProperty(Email::class, 'config');
        $mailables = Mail::sent(Email::class)->merge(Mail::queued(Email::class));

        return $mailables->map(fn (Email $mail): string => (string) $config->getValue($mail)['to'])->values()->all();
    }
}
