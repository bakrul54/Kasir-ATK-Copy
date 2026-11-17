<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductWholesaleGroup;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class StoreBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_download_and_restore_database_and_uploaded_files(): void
    {
        Storage::fake('public');
        Schema::create('abouts', static function (Blueprint $table): void {
            $table->id();
        });

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $category = ProductCategory::create(['name' => 'ATK']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kertas A4',
            'unit' => 'rim',
            'sale_price' => 50000,
            'photo_path' => 'products/kertas-a4.png',
        ]);
        $wholesaleGroup = ProductWholesaleGroup::create([
            'name' => 'Print',
            'minimum_quantity' => 20,
        ]);
        $product->update(['wholesale_group_id' => $wholesaleGroup->id]);
        $imageContents = UploadedFile::fake()->image('sample.png')->getContent();
        Storage::disk('public')->put($product->photo_path, $imageContents);
        Storage::disk('public')->put('store/logo.png', $imageContents);

        $this->get(route('pengaturan'))->assertOk()
            ->assertSee('Unduh Backup')
            ->assertSee('PULIHKAN');

        $response = $this->get(route('pengaturan.backup.download'))
            ->assertDownload('spot-print-backup-'.now()->format('Ymd-His').'.zip');
        $backupPath = $response->baseResponse->getFile()->getPathname();

        try {
            $archive = new ZipArchive;
            $this->assertSame(true, $archive->open($backupPath));
            $database = json_decode($archive->getFromName('database.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('spot-print-backup', $database['format']);
            $this->assertArrayNotHasKey('abouts', $database['database']);
            $this->assertSame(2, $database['version']);
            $this->assertSame('Print', $database['database']['product_wholesale_groups'][0]['name']);
            $this->assertSame($imageContents, $archive->getFromName('uploads/products/kertas-a4.png'));
            $this->assertSame($imageContents, $archive->getFromName('uploads/store/logo.png'));
            $archive->close();

            $product->update(['name' => 'Nama baru']);
            Storage::disk('public')->delete($product->photo_path);

            $this->post(route('pengaturan.backup.restore'), [
                'backup_file' => new UploadedFile($backupPath, 'backup.zip', 'application/zip', null, true),
                'confirmation' => 'PULIHKAN',
            ])->assertRedirect(route('login'))
                ->assertSessionHas('success', 'Backup berhasil dipulihkan. Silakan masuk kembali.');

            $this->assertDatabaseHas('products', [
                'id' => $product->id,
                'name' => 'Kertas A4',
                'wholesale_group_id' => $wholesaleGroup->id,
            ]);
            $this->assertDatabaseHas('product_wholesale_groups', [
                'id' => $wholesaleGroup->id,
                'name' => 'Print',
            ]);
            Storage::disk('public')->assertExists('products/kertas-a4.png');
            Storage::disk('public')->assertExists('store/logo.png');
            $this->assertGuest();
            $this->get(route('login'))->assertOk()
                ->assertSee('Backup berhasil dipulihkan. Silakan masuk kembali.');
        } finally {
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
        }
    }

    public function test_restore_rejects_invalid_backup_without_changing_store_data(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $category = ProductCategory::create(['name' => 'ATK']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kertas A4',
            'unit' => 'rim',
            'sale_price' => 50000,
        ]);
        $backupPath = tempnam(sys_get_temp_dir(), 'invalid-spot-print-backup-');
        $archive = new ZipArchive;
        $this->assertSame(true, $archive->open($backupPath, ZipArchive::OVERWRITE));
        $this->assertTrue($archive->addFromString('database.json', '{invalid json'));
        $archive->close();

        try {
            $this->from(route('pengaturan'))->post(route('pengaturan.backup.restore'), [
                'backup_file' => new UploadedFile($backupPath, 'backup.zip', 'application/zip', null, true),
                'confirmation' => 'PULIHKAN',
            ])->assertRedirect(route('pengaturan'))
                ->assertSessionHasErrors('backup_file');

            $this->assertDatabaseHas('products', [
                'id' => $product->id,
                'name' => 'Kertas A4',
            ]);
        } finally {
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
        }
    }

    public function test_restore_accepts_version_one_backup_without_wholesale_groups(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $category = ProductCategory::create(['name' => 'Jasa']);
        $group = ProductWholesaleGroup::create(['name' => 'Print', 'minimum_quantity' => 20]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Print Hitam Putih',
            'unit' => 'lembar',
            'tracks_stock' => false,
            'sale_price' => 300,
            'wholesale_price' => 200,
            'wholesale_min_qty' => 20,
            'wholesale_group_id' => $group->id,
        ]);

        $response = $this->get(route('pengaturan.backup.download'))
            ->assertDownload('spot-print-backup-'.now()->format('Ymd-His').'.zip');
        $backupPath = $response->baseResponse->getFile()->getPathname();

        try {
            $archive = new ZipArchive;
            $this->assertSame(true, $archive->open($backupPath));
            $database = json_decode($archive->getFromName('database.json'), true, 512, JSON_THROW_ON_ERROR);
            $database['version'] = 1;
            unset($database['database']['product_wholesale_groups']);
            foreach ($database['database']['products'] as &$backupProduct) {
                unset($backupProduct['wholesale_group_id']);
            }
            unset($backupProduct);
            $this->assertTrue($archive->deleteName('database.json'));
            $this->assertTrue($archive->addFromString(
                'database.json',
                json_encode($database, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));
            $this->assertTrue($archive->close());

            $this->post(route('pengaturan.backup.restore'), [
                'backup_file' => new UploadedFile($backupPath, 'backup-v1.zip', 'application/zip', null, true),
                'confirmation' => 'PULIHKAN',
            ])->assertRedirect(route('login'))
                ->assertSessionHas('success', 'Backup berhasil dipulihkan. Silakan masuk kembali.');

            $this->assertDatabaseHas('products', [
                'id' => $product->id,
                'wholesale_group_id' => null,
            ]);
            $this->assertDatabaseCount('product_wholesale_groups', 0);
        } finally {
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
        }
    }

    public function test_restore_requires_explicit_confirmation(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $backupPath = tempnam(sys_get_temp_dir(), 'unconfirmed-spot-print-backup-');
        $archive = new ZipArchive;
        $this->assertSame(true, $archive->open($backupPath, ZipArchive::OVERWRITE));
        $this->assertTrue($archive->addFromString('database.json', '{}'));
        $archive->close();

        try {
            $this->from(route('pengaturan'))->post(route('pengaturan.backup.restore'), [
                'backup_file' => new UploadedFile($backupPath, 'backup.zip', 'application/zip', null, true),
                'confirmation' => 'RESTORE',
            ])->assertRedirect(route('pengaturan'))
                ->assertSessionHasErrors('confirmation');

            $this->assertDatabaseHas('users', ['id' => $owner->id]);
        } finally {
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
        }
    }

    public function test_non_owner_cannot_access_backup_or_restore(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('pengaturan.backup.download'))->assertForbidden();
        $this->post(route('pengaturan.backup.restore'), [])->assertForbidden();
    }

    public function test_guests_cannot_access_backup_or_restore(): void
    {
        $this->get(route('pengaturan.backup.download'))->assertRedirect(route('login'));
        $this->post(route('pengaturan.backup.restore'), [])->assertRedirect(route('login'));
    }
}
