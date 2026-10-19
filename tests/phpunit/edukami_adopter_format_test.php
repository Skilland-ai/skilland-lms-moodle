<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;
use mod_skilland\local\edukami_adopter;

/**
 * The plain-text report the adopt_edukami CLI prints (SKL-997): ids and names only, totals across courses.
 */
class edukami_adopter_format_test extends TestCase {

    private static function activity(string $outcome, int $id, string $reason = '', array $warnings = []): array {
        return ['outcome' => $outcome, 'edukamiid' => $id, 'cmid' => $id + 100, 'name' => 'Topic ' . $id,
            'reason' => $reason, 'skillandid' => null, 'warnings' => $warnings];
    }

    private static function counts(array $activities): array {
        $counts = [edukami_adopter::ADOPTED => 0, edukami_adopter::ALREADY => 0, edukami_adopter::SKIPPED => 0,
            edukami_adopter::FAILED => 0];
        foreach ($activities as $activity) {
            $counts[$activity['outcome']]++;
        }
        return $counts;
    }

    public function test_formats_each_course_its_activities_warnings_and_the_totals(): void {
        $first = [
            self::activity(edukami_adopter::ADOPTED, 1, 'skilland 7: topic t1, 2 lesson(s), SCORM cm 50',
                ['lesson x (Intro) has no SCO in the SCORM: its progress is not read']),
            self::activity(edukami_adopter::ALREADY, 2),
        ];
        $second = [self::activity(edukami_adopter::FAILED, 3, 'Skilland did not list the topics: boom')];
        $reports = [
            ['courseid' => 10, 'shortname' => 'C10', 'skillid' => 'skill-a', 'activities' => $first,
                'counts' => self::counts($first)],
            ['courseid' => 11, 'shortname' => 'C11', 'skillid' => null, 'activities' => $second,
                'counts' => self::counts($second)],
        ];

        $this->assertSame([
            'Course 10 (C10), Skilland skill skill-a',
            '  [adopted] edukami 1 (cm 101) "Topic 1": skilland 7: topic t1, 2 lesson(s), SCORM cm 50',
            '      warning: lesson x (Intro) has no SCO in the SCORM: its progress is not read',
            '  [already adopted] edukami 2 (cm 102) "Topic 2"',
            '  adopted 1, already adopted 1, skipped 0, failed 0',
            'Course 11 (C11), Skilland skill none',
            '  [failed] edukami 3 (cm 103) "Topic 3": Skilland did not list the topics: boom',
            '  adopted 0, already adopted 0, skipped 0, failed 1',
            'Done: 2 course(s); adopted 1, already adopted 1, skipped 0, failed 1',
        ], edukami_adopter::format($reports, true));
    }

    public function test_dry_run_and_empty_reports_say_so(): void {
        $this->assertSame(
            ['Dry run, nothing written: 0 course(s); adopted 0, already adopted 0, skipped 0, failed 0'],
            edukami_adopter::format([], false)
        );
    }

    public function test_prints_the_note_of_a_course_matched_against_its_mapped_skill(): void {
        $activities = [self::activity(edukami_adopter::ADOPTED, 1,
            'would adopt: topic t1 (matched by name), 2 lesson(s) (2 matched by name), SCORM cm 50')];
        $reports = [['courseid' => 10, 'shortname' => 'C10', 'skillid' => 'skill-b',
            'note' => 'the course is mapped to Skilland skill skill-b, not to skill-a (the Edukami skill\'s): '
                . 'its activities are matched against the mapped skill',
            'activities' => $activities, 'counts' => self::counts($activities)]];

        $this->assertSame([
            'Course 10 (C10), Skilland skill skill-b',
            '  note: the course is mapped to Skilland skill skill-b, not to skill-a (the Edukami skill\'s): '
                . 'its activities are matched against the mapped skill',
            '  [adopted] edukami 1 (cm 101) "Topic 1": would adopt: topic t1 (matched by name), 2 lesson(s) '
                . '(2 matched by name), SCORM cm 50',
            '  adopted 1, already adopted 0, skipped 0, failed 0',
            'Dry run, nothing written: 1 course(s); adopted 1, already adopted 0, skipped 0, failed 0',
        ], edukami_adopter::format($reports, false));
    }

    /**
     * @dataProvider names
     */
    public function test_normalises_names_for_matching(string $name, string $expected): void {
        $this->assertSame($expected, edukami_adopter::normalise_name($name));
    }

    public static function names(): array {
        return [
            'numbering prefix' => ['T1 - El Prácticum como espacio formativo clave', 'el prácticum como espacio formativo clave'],
            'two-digit prefix, no spaces' => ['T12-Evaluación', 'evaluación'],
            'lower-case prefix, en dash' => ['t3 – La tutoría', 'la tutoría'],
            'colon prefix' => ['T4: Cierre', 'cierre'],
            'whitespace and case' => ["  La  tutoría\tACADÉMICA ", 'la tutoría académica'],
            'no prefix' => ['Introducción', 'introducción'],
            'T without a number is kept' => ['Tutoría - inicio', 'tutoría - inicio'],
            'number without a separator is kept' => ['T1 Introducción', 't1 introducción'],
            'empty' => ['  ', ''],
        ];
    }
}
