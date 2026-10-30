<?php

use App\Enums\Term;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function sampleScheduleUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'jadwal.xlsx',
        file_get_contents(base_path('tests/Fixtures/jadwal-if-genap-2025-2026.xlsx')),
    );
}

test('guests are redirected to the login page', function () {
    $this->get(route('admin.import'))->assertRedirect(route('login'));
});

test('students cannot open the import page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.import'))->assertForbidden();
});

test('admins can open the import page', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.import'))->assertOk()->assertSee('Import Jadwal');
});

test('the active period is preselected as the import target', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicPeriod::factory()->of(2026, Term::Ganjil)->create();
    $active = AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();

    Livewire::test('pages::admin.import')->assertSet('periodId', (string) $active->id);
});

test('uploading the sample spreadsheet imports its courses and classes into the selected period', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();
    $target = AcademicPeriod::factory()->of(2024, Term::Genap)->create();

    $component = Livewire::test('pages::admin.import')
        ->set('periodId', (string) $target->id)
        ->set('file', sampleScheduleUpload())
        ->call('import');

    $component->assertHasNoErrors()->assertSet('file', null);

    expect(Course::count())->toBe(24);
    expect(CourseClass::count())->toBe(44);
    expect(CourseClass::query()->inPeriod($target)->count())->toBe(44);
    expect($component->get('result'))->toMatchArray([
        'classes_created' => 44,
        'classes_updated' => 0,
        'courses_created' => 24,
    ]);
});

test('replacing existing data only removes the classes and enrollments of the selected period', function () {
    $this->actingAs(User::factory()->admin()->create());
    $target = AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();
    $otherPeriod = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    $student = User::factory()->create();
    $oldCourse = Course::factory()->create(['name' => 'Mata Kuliah Lama']);
    $oldClass = CourseClass::factory()->for($oldCourse)->inPeriod($target)->create();
    $otherClass = CourseClass::factory()->for($oldCourse)->inPeriod($otherPeriod)->create();
    $student->courseClasses()->attach([$oldClass->id, $otherClass->id]);

    Livewire::test('pages::admin.import')
        ->set('periodId', (string) $target->id)
        ->set('file', sampleScheduleUpload())
        ->set('replaceExisting', true)
        ->call('import')
        ->assertHasNoErrors();

    $this->assertModelMissing($oldClass);
    $this->assertModelExists($otherClass);
    $this->assertModelExists($oldCourse);
    $this->assertDatabaseMissing('enrollments', ['user_id' => $student->id, 'course_class_id' => $oldClass->id]);
    $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_class_id' => $otherClass->id]);
    expect(CourseClass::query()->inPeriod($target)->count())->toBe(44);
});

test('importing without any academic period is rejected', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.import')
        ->assertSee('Belum ada periode akademik')
        ->set('file', sampleScheduleUpload())
        ->call('import')
        ->assertHasErrors(['periodId' => 'required']);

    expect(CourseClass::count())->toBe(0);
});

test('files that are not xlsx or csv are rejected', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicPeriod::factory()->active()->create();

    Livewire::test('pages::admin.import')
        ->set('file', UploadedFile::fake()->create('jadwal.pdf', 10, 'application/pdf'))
        ->call('import')
        ->assertHasErrors(['file' => 'extensions']);

    expect(CourseClass::count())->toBe(0);
});

test('a spreadsheet without a recognisable header shows the import error', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicPeriod::factory()->active()->create();

    $component = Livewire::test('pages::admin.import')
        ->set('file', UploadedFile::fake()->createWithContent('jadwal.csv', "foo,bar\n1,2\n"))
        ->call('import');

    expect($component->errors()->first('file'))
        ->toBe('Baris header tidak ditemukan. Pastikan ada kolom "Mata Kuliah", "Hari", "SKS", "Mulai" dan "Selesai".');
    expect($component->get('result'))->toBeNull();
});

test('admins can download the sample spreadsheet', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.import')
        ->call('downloadSample')
        ->assertFileDownloaded('contoh-jadwal.xlsx');
});
