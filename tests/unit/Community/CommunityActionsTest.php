<?php

namespace Tests\Unit\Community;

use CodeIgniter\Test\CIUnitTestCase;
use App\Controllers\Api\CommunityActions\CommunityActionsController;

class CommunityActionsTest extends CIUnitTestCase
{
    public function testFormatActionReturnsAllRequiredKeys(): void
    {
        $controller = new class extends CommunityActionsController {
            public function callFormat(array $row, array $set): array
            {
                return $this->formatAction($row, $set);
            }
        };

        $row = [
            'id'               => 1,
            'title'            => 'Test Action',
            'description'      => 'Desc',
            'action_type'      => 'attend',
            'circle_id'        => null,
            'movement_id'      => null,
            'discussion_id'    => null,
            'cta_label'        => 'Take Action',
            'cta_url'          => null,
            'deadline'         => null,
            'participant_goal' => null,
            'interested_count' => 5,
            'completed_count'  => 2,
            'status'           => 'active',
            'created_by'       => 10,
            'creator_name'     => 'Jane',
            'created_at'       => '2026-05-08 00:00:00',
        ];

        $result = $controller->callFormat($row, []);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('action_type', $result);
        $this->assertArrayHasKey('interested_count', $result);
        $this->assertArrayHasKey('completed_count', $result);
        $this->assertArrayHasKey('my_participation', $result);
        $this->assertArrayHasKey('creator', $result);
        $this->assertNull($result['my_participation']);
        $this->assertSame(5, $result['interested_count']);
        $this->assertSame(2, $result['completed_count']);
    }

    public function testFormatActionWithParticipation(): void
    {
        $controller = new class extends CommunityActionsController {
            public function callFormat(array $row, array $set): array
            {
                return $this->formatAction($row, $set);
            }
        };

        $row = [
            'id'               => 7,
            'title'            => 'Vote Now',
            'description'      => null,
            'action_type'      => 'register',
            'circle_id'        => null,
            'movement_id'      => null,
            'discussion_id'    => null,
            'cta_label'        => 'Register',
            'cta_url'          => null,
            'deadline'         => null,
            'participant_goal' => null,
            'interested_count' => 1,
            'completed_count'  => 0,
            'status'           => 'active',
            'created_by'       => 1,
            'creator_name'     => 'Admin',
            'created_at'       => '2026-05-08 00:00:00',
        ];

        $participatingSet = [7 => ['action_id' => 7, 'user_id' => 42, 'participation_type' => 'interested']];
        $result = $controller->callFormat($row, $participatingSet);

        $this->assertSame('interested', $result['my_participation']);
    }
}
