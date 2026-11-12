<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use DatabaseTransactions;

    private const UID = 999001; // id fiktif: tidak menyentuh foto user asli

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $u = AclUser::findOrFail(122);
        $u->id_user = self::UID;       // hanya di memori; tidak disimpan
        Sanctum::actingAs($u, ['*']);
    }

    private function png(int $w = 600, int $h = 300): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200));
        $f = tempnam(sys_get_temp_dir(), 'ph') . '.png';
        imagepng($im, $f);

        return new UploadedFile($f, 'a.png', 'image/png', null, true);
    }

    public function test_tanpa_foto_mengembalikan_404(): void
    {
        $this->getJson('/api/v1/profile/photo')->assertNotFound();
    }

    public function test_unggah_menghasilkan_jpeg_persegi_256(): void
    {
        $this->post('/api/v1/profile/photo', ['photo' => $this->png()], ['Accept' => 'application/json'])->assertOk();
        $res = $this->get('/api/v1/profile/photo')->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $info = getimagesizefromstring($res->getContent());
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
    }

    public function test_berkas_bukan_gambar_ditolak(): void
    {
        $f = UploadedFile::fake()->createWithContent('x.png', '<?php echo 1; ?>');
        $this->post('/api/v1/profile/photo', ['photo' => $f], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_hapus_foto(): void
    {
        $this->post('/api/v1/profile/photo', ['photo' => $this->png()], ['Accept' => 'application/json'])->assertOk();
        $this->deleteJson('/api/v1/profile/photo')->assertOk();
        $this->getJson('/api/v1/profile/photo')->assertNotFound();
    }

    public function test_perlu_login(): void
    {
        $this->app['auth']->forgetGuards();
        $this->refreshApplication();
        $this->getJson('/api/v1/profile/photo')->assertUnauthorized();
    }
}
