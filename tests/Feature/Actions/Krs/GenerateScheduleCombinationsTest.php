<?php

use App\Actions\Krs\GenerateScheduleCombinations;
use App\Actions\Krs\SyncStudentClasses;
use App\Enums\Day;
use App\Enums\Term;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Lecturer;
use App\Models\User;
use App\Support\PlannerPreferences;
use App\Support\PlannerResult;
use App\Support\ScheduleCombination;
use Database\Seeders\ScheduleSeeder;
use Illuminate\Support\Collection;

/**
 * Create a class for the course at the given day and start time (two 50-minute slots).
 *
 * @param  array<string, mixed>  $attributes
 */
function plannerClass(Course $course, Day $day, string $start, array $attributes = []): CourseClass
{
    return CourseClass::factory()->for($course)->at($day, $start)->create($attributes);
}

/**
 * Run the planner for the student with the given preferences.
 */
function plan(User $student, mixed ...$preferences): PlannerResult
{
    return app(GenerateScheduleCombinations::class)->handle($student, currentKrsPeriod(), new PlannerPreferences(...$preferences));
}

/**
 * Get the course IDs of a combination, sorted.
 *
 * @return list<int>
 */
function combinationCourseIds(ScheduleCombination $combination): array
{
    return collect($combination->classes)->pluck('course_id')->sort()->values()->all();
}

it('builds conflict-free combinations with every mandatory course and the minimum electives', function () {
    $student = User::factory()->create();
    $mandatory = Course::factory()->mandatory()->create(['semester' => 6]);
    plannerClass($mandatory, Day::Senin, '07:00', ['name' => 'A']);
    plannerClass($mandatory, Day::Selasa, '07:00', ['name' => 'B']);
    $electives = Course::factory()->elective()->count(4)->create();
    plannerClass($electives[0], Day::Senin, '07:00');
    plannerClass($electives[1], Day::Selasa, '07:00');
    plannerClass($electives[2], Day::Rabu, '10:00');
    plannerClass($electives[3], Day::Rabu, '10:00');

    $result = plan($student, maxSks: 24, semester: 6, minElectives: 2);

    expect($result->combinations)->not->toBeEmpty()
        ->and($result->warnings)->toBe([]);

    foreach ($result->combinations as $combination) {
        $classes = collect($combination->classes);

        expect($classes->pluck('course_id'))->toContain($mandatory->id)
            ->and($combination->electiveCount)->toBeGreaterThanOrEqual(2)
            ->and($combination->totalSks)->toBeLessThanOrEqual(24)
            ->and($classes->pluck('course_id')->duplicates())->toBeEmpty();

        $classes->each(fn (CourseClass $class) => $classes
            ->reject(fn (CourseClass $other) => $other->is($class))
            ->each(fn (CourseClass $other) => expect($class->overlaps($other))->toBeFalse()));
    }

    expect(collect($result->combinations)->map(fn ($combination) => implode('-', combinationCourseIds($combination)))->duplicates())->toBeEmpty();
});

it('finds the highest SKS total that a brute-force search can reach', function () {
    $student = User::factory()->create();
    $mandatory = Course::factory()->mandatory()->create(['semester' => 6, 'sks' => 3]);
    plannerClass($mandatory, Day::Senin, '10:00', ['name' => 'A']);
    plannerClass($mandatory, Day::Selasa, '10:00', ['name' => 'B']);
    plannerClass($mandatory, Day::Rabu, '07:00', ['name' => 'C']);

    $slots = [
        [Day::Senin, '07:00', 3], [Day::Senin, '10:00', 4], [Day::Selasa, '10:00', 2], [Day::Selasa, '13:30', 3],
        [Day::Rabu, '07:00', 3], [Day::Rabu, '07:00', 4], [Day::Kamis, '09:00', 3], [Day::Kamis, '10:00', 2],
        [Day::Senin, '13:30', 3], [Day::Jumat, '13:30', 4],
    ];

    foreach ($slots as [$day, $start, $sks]) {
        plannerClass(Course::factory()->elective()->create(['sks' => $sks]), $day, $start);
    }

    $maxSks = 18;
    $minElectives = 2;

    $classes = CourseClass::with('course')->get()->values();
    $bestSks = 0;

    for ($mask = 1; $mask < (1 << $classes->count()); $mask++) {
        $selection = $classes->filter(fn (CourseClass $class, int $index) => ($mask >> $index) & 1)->values();
        $sks = $selection->sum('course.sks');

        if ($sks > $maxSks || $sks <= $bestSks
            || $selection->where('course_id', $mandatory->id)->count() !== 1
            || $selection->pluck('course_id')->duplicates()->isNotEmpty()
            || $selection->where('course_id', '!=', $mandatory->id)->count() < $minElectives
            || $selection->contains(fn (CourseClass $class, int $i) => $selection->slice($i + 1)->contains(fn (CourseClass $other) => $class->overlaps($other)))) {
            continue;
        }

        $bestSks = $sks;
    }

    $result = plan($student, maxSks: $maxSks, semester: 6, minElectives: $minElectives, limit: 3);

    expect($bestSks)->toBeGreaterThan(0)
        ->and($result->truncated)->toBeFalse()
        ->and($result->combinations[0]->totalSks)->toBe($bestSks);
});

it('produces combinations that pass the KRS rules', function () {
    $student = User::factory()->maxSks(20)->create();
    $mandatory = Course::factory()->mandatory()->create(['semester' => 6]);
    plannerClass($mandatory, Day::Senin, '07:00');
    Course::factory()->elective()->count(6)->create()->each(
        fn (Course $course, int $index) => plannerClass($course, Day::from($index % 5 + 1), $index < 5 ? '10:00' : '13:30'),
    );

    $result = plan($student, maxSks: 20, semester: 6);
    app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), $result->combinations[0]->classIds());

    expect($student->takenSks(currentKrsPeriod()))->toBe($result->combinations[0]->totalSks)
        ->and($result->combinations[0]->totalSks)->toBe(18);
});

it('never uses classes of avoided lecturers, avoided days or outside the time window', function () {
    $student = User::factory()->create();
    $avoided = Lecturer::factory()->create();
    $courses = Course::factory()->elective()->count(5)->create();
    plannerClass($courses[0], Day::Senin, '10:00')->lecturers()->attach($avoided);
    plannerClass($courses[1], Day::Jumat, '10:00');
    plannerClass($courses[2], Day::Selasa, '07:00');
    plannerClass($courses[3], Day::Selasa, '15:30');
    $allowed = plannerClass($courses[4], Day::Rabu, '10:00');

    $result = plan($student, minElectives: 1, avoidedLecturerIds: [$avoided->id], avoidedDays: [Day::Jumat->value], earliestStart: '09:00', latestEnd: '15:20');

    expect($result->combinations)->toHaveCount(1)
        ->and($result->combinations[0]->classIds())->toBe([$allowed->id]);
});

it('filters classes by language and study program', function () {
    $student = User::factory()->create();
    $courses = Course::factory()->elective()->count(4)->create();
    plannerClass($courses[0], Day::Senin, '07:00', ['language' => 'Indonesia', 'prodi' => 'IF']);
    plannerClass($courses[1], Day::Selasa, '07:00', ['language' => 'English', 'prodi' => 'IUP']);
    $shared = plannerClass($courses[2], Day::Rabu, '07:00', ['language' => 'English', 'prodi' => 'IF, RKA, IUP']);
    $english = plannerClass($courses[3], Day::Kamis, '07:00', ['language' => 'English', 'prodi' => 'IF']);

    $result = plan($student, minElectives: 1, language: 'Inggris', prodi: ['IF']);

    expect(collect($result->combinations[0]->classIds())->sort()->values()->all())
        ->toBe(collect([$shared->id, $english->id])->sort()->values()->all());
});

it('does not require a mandatory course the student excludes', function () {
    $student = User::factory()->create();
    $passed = Course::factory()->mandatory()->create(['semester' => 6]);
    plannerClass($passed, Day::Senin, '07:00');
    $elective = Course::factory()->elective()->create();
    plannerClass($elective, Day::Senin, '07:00');

    $result = plan($student, semester: 6, minElectives: 1, excludedCourseIds: [$passed->id]);

    expect(combinationCourseIds($result->combinations[0]))->toBe([$elective->id]);
});

it('always includes explicitly required courses', function () {
    $student = User::factory()->create();
    $general = Course::factory()->general()->create();
    plannerClass($general, Day::Kamis, '07:00');
    $elective = Course::factory()->elective()->create();
    plannerClass($elective, Day::Senin, '07:00');

    $result = plan($student, minElectives: 1, requiredCourseIds: [$general->id]);

    foreach ($result->combinations as $combination) {
        expect(combinationCourseIds($combination))->toContain($general->id);
    }
});

it('explains when a required course has no class left after filtering', function () {
    $student = User::factory()->create();
    $mandatory = Course::factory()->mandatory()->create(['semester' => 6, 'name' => 'Pemrograman Perangkat Bergerak']);
    plannerClass($mandatory, Day::Jumat, '07:00');

    $result = plan($student, semester: 6, minElectives: 0, avoidedDays: [Day::Jumat->value]);

    expect($result->combinations)->toBe([])
        ->and($result->warnings)->toBe(['Mata kuliah Pemrograman Perangkat Bergerak wajib diambil, tetapi tidak ada kelasnya yang memenuhi filter.']);
});

it('explains when there are not enough electives', function () {
    $student = User::factory()->create();
    plannerClass(Course::factory()->elective()->create(), Day::Senin, '07:00');

    $result = plan($student, minElectives: 2);

    expect($result->combinations)->toBe([])
        ->and($result->warnings)->toBe(['Hanya 1 mata kuliah pilihan yang memenuhi filter, kurang dari minimal 2.']);
});

it('explains when every combination clashes', function () {
    $student = User::factory()->create();
    $mandatory = Course::factory()->mandatory()->create(['semester' => 6]);
    plannerClass($mandatory, Day::Senin, '07:00');
    plannerClass(Course::factory()->elective()->create(), Day::Senin, '07:00');

    $result = plan($student, semester: 6, minElectives: 1);

    expect($result->combinations)->toBe([])
        ->and($result->warnings[0])->toStartWith('Tidak ada kombinasi tanpa bentrok');
});

it('ranks combinations with priority courses first', function () {
    $student = User::factory()->create();
    $courses = Course::factory()->elective()->count(3)->create();
    plannerClass($courses[0], Day::Senin, '07:00');
    plannerClass($courses[1], Day::Senin, '07:00');
    plannerClass($courses[2], Day::Selasa, '07:00');

    $result = plan($student, maxSks: 3, minElectives: 1, priorityCourseIds: [$courses[1]->id]);

    expect(combinationCourseIds($result->combinations[0]))->toBe([$courses[1]->id])
        ->and($result->combinations[0]->priorityCount)->toBe(1);
});

it('prefers the class taught by a favourite lecturer', function () {
    $student = User::factory()->create();
    $favourite = Lecturer::factory()->create();
    $course = Course::factory()->elective()->create();
    plannerClass($course, Day::Senin, '07:00', ['name' => 'A']);
    $preferred = plannerClass($course, Day::Kamis, '13:30', ['name' => 'B']);
    $preferred->lecturers()->attach($favourite);

    $result = plan($student, minElectives: 1, preferredLecturerIds: [$favourite->id]);

    expect($result->combinations[0]->classIds())->toBe([$preferred->id]);
});

it('packs classes into fewer days when asked', function () {
    $student = User::factory()->create();
    $first = Course::factory()->elective()->create();
    plannerClass($first, Day::Senin, '07:00', ['name' => 'A']);
    plannerClass($first, Day::Rabu, '07:00', ['name' => 'B']);
    $second = Course::factory()->elective()->create();
    plannerClass($second, Day::Selasa, '10:00', ['name' => 'A']);
    plannerClass($second, Day::Rabu, '10:00', ['name' => 'B']);

    $result = plan($student, minElectives: 2, compactDays: true);

    expect($result->combinations[0]->dayCount)->toBe(1)
        ->and(collect($result->combinations[0]->classes)->pluck('day')->unique()->all())->toBe([Day::Rabu]);
});

it('prefers less idle time between classes when asked', function () {
    $student = User::factory()->create();
    $first = Course::factory()->elective()->create();
    plannerClass($first, Day::Senin, '07:00');
    $second = Course::factory()->elective()->create();
    $farClass = plannerClass($second, Day::Senin, '15:30', ['name' => 'A']);
    $nearClass = plannerClass($second, Day::Senin, '09:00', ['name' => 'B']);

    $result = plan($student, minElectives: 2, minimizeGaps: true);

    expect($result->combinations[0]->classIds())->toContain($nearClass->id)
        ->and($result->combinations[0]->classIds())->not->toContain($farClass->id)
        ->and($result->combinations[0]->gapMinutes)->toBe(0);
});

it('keeps the classes already in the KRS when locking them, even outside the filters', function () {
    $student = User::factory()->create();
    $course = Course::factory()->elective()->create();
    $locked = plannerClass($course, Day::Jumat, '07:00', ['name' => 'A']);
    plannerClass($course, Day::Senin, '07:00', ['name' => 'B']);
    $student->courseClasses()->attach($locked);

    $result = plan($student, minElectives: 1, avoidedDays: [Day::Jumat->value], keepCurrentClasses: true);

    expect($result->combinations[0]->classIds())->toBe([$locked->id]);
});

it('skips full classes except the one the student already holds', function () {
    $student = User::factory()->create();
    $courses = Course::factory()->elective()->count(2)->create();
    $full = plannerClass($courses[0], Day::Senin, '07:00', ['capacity' => 1]);
    $full->students()->attach(User::factory()->create());
    $held = plannerClass($courses[1], Day::Selasa, '07:00', ['capacity' => 1]);
    $held->students()->attach($student);

    $result = plan($student, minElectives: 1);

    expect($result->combinations[0]->classIds())->toBe([$held->id]);
});

it('respects the maximum number of electives', function () {
    $student = User::factory()->create();
    Course::factory()->elective()->count(4)->create()->each(
        fn (Course $course, int $index) => plannerClass($course, Day::from($index + 1), '07:00'),
    );

    $result = plan($student, minElectives: 1, maxElectives: 2);

    expect(collect($result->combinations)->max('electiveCount'))->toBe(2);
});

it('only adds general courses when they are allowed', function (bool $includeGeneral, bool $expected) {
    $student = User::factory()->create();
    $general = Course::factory()->general()->create();
    plannerClass($general, Day::Selasa, '07:00');
    plannerClass(Course::factory()->elective()->create(), Day::Senin, '07:00');

    $result = plan($student, minElectives: 1, includeGeneral: $includeGeneral);

    expect(collect($result->combinations)->contains(fn ($combination) => in_array($general->id, combinationCourseIds($combination), true)))
        ->toBe($expected);
})->with([
    'excluded by default' => [false, false],
    'included when allowed' => [true, true],
]);

it('returns at most the requested number of combinations', function () {
    $student = User::factory()->create();
    Course::factory()->elective()->count(5)->create()->each(
        fn (Course $course, int $index) => plannerClass($course, Day::from($index + 1), '07:00'),
    );

    $result = plan($student, maxSks: 6, minElectives: 1, limit: 3);

    expect($result->combinations)->toHaveCount(3)
        ->and(collect($result->combinations)->pluck('score')->all())
        ->toBe(collect($result->combinations)->pluck('score')->sortDesc()->values()->all());
});

it('ranks the real sample schedule within the time budget', function () {
    $this->seed(ScheduleSeeder::class);
    $student = User::factory()->create();

    $result = plan($student, maxSks: 24, semester: 6, includeGeneral: true, includeOtherMandatory: true, compactDays: true, minimizeGaps: true, limit: 10);

    expect($result->truncated)->toBeFalse()
        ->and($result->combinations)->toHaveCount(10)
        ->and($result->combinations[0]->totalSks)->toBe(24)
        ->and(Collection::make($result->combinations[0]->classes)->pluck('course.name'))
        ->toContain('Interaksi Manusia dan Komputer', 'Pemrograman Perangkat Bergerak');
});

it('only uses classes scheduled in the requested period', function () {
    $student = User::factory()->create();
    $period = currentKrsPeriod();
    $otherPeriod = AcademicPeriod::factory()->of(2024, Term::Genap)->create();
    $course = Course::factory()->elective()->create();
    $inPeriod = plannerClass($course, Day::Selasa, '07:00');
    CourseClass::factory()->for($course)->inPeriod($otherPeriod)->at(Day::Senin, '07:00')->create();
    CourseClass::factory()->for(Course::factory()->elective())->inPeriod($otherPeriod)->at(Day::Rabu, '07:00')->create();

    $result = app(GenerateScheduleCombinations::class)->handle($student, $period, new PlannerPreferences(minElectives: 1));

    expect($result->combinations)->toHaveCount(1)
        ->and($result->combinations[0]->classIds())->toBe([$inPeriod->id]);
});

it('does not lock classes the student holds in another period', function () {
    $student = User::factory()->create();
    $period = currentKrsPeriod();
    $course = Course::factory()->elective()->create();
    $current = plannerClass($course, Day::Selasa, '07:00');
    $student->courseClasses()->attach(CourseClass::factory()->inPeriod(AcademicPeriod::factory()->of(2024, Term::Genap)->create())->create());

    $result = app(GenerateScheduleCombinations::class)->handle($student, $period, new PlannerPreferences(minElectives: 1, keepCurrentClasses: true));

    expect($result->combinations[0]->classIds())->toBe([$current->id]);
});

it('explains when the period has no classes', function () {
    $period = AcademicPeriod::factory()->active()->of(2026, Term::Ganjil)->create();

    $result = app(GenerateScheduleCombinations::class)->handle(User::factory()->create(), $period, new PlannerPreferences);

    expect($result->combinations)->toBe([])
        ->and($result->warnings)->toBe(['Belum ada data kelas untuk periode 2026/2027 Ganjil. Minta admin mengimpor jadwal terlebih dahulu.']);
});
