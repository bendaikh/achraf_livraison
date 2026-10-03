<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/** T1 — the product is "Lav'Fast" / "Flow" everywhere, no old spelling left in the UI. */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    // All feature tests share one in-memory schema (seeded once): keep $seed consistent.
    protected bool $seed = true;

    public function test_browser_title_uses_the_brand(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Lav&#039;Fast Flow</title>', $html);
        $this->assertStringNotContainsStringIgnoringCase('Lavafast', $html);
    }

    public function test_defaults_use_the_brand(): void
    {
        $this->assertSame("Lav'Fast Flow", Company::default()->name);
        $this->assertSame("Lav'Fast Flow", Setting::DEFAULTS['company_name']);
        $this->assertSame("Lav'Fast", config('brand.name'));
        $this->assertSame('Flow', config('brand.suffix'));
    }

    public function test_no_old_brand_variant_left_in_ui_sources(): void
    {
        $finder = (new Finder)->files()->in([base_path('resources/js'), base_path('resources/views'), base_path('app')])->name(['*.jsx', '*.js', '*.php']);
        $offenders = [];
        foreach ($finder as $file) {
            $content = $file->getContents();
            // "lavfast" alone is fine in technical keys / the lavfast-flow.com domain.
            if (preg_match('/Lavafast|Lavfast(?![-:.\w])|Lavfast Livraison|LAV\'FAST|LavFast/', $content, $m)) {
                $offenders[] = $file->getRelativePathname().' → '.$m[0];
            }
        }
        $this->assertSame([], $offenders);
    }
}
