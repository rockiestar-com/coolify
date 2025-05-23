<?php

namespace Tests\Feature;

use App\Livewire\Project\Application\General as ApplicationGeneral;
use App\Livewire\Project\New\PublicGitRepository;
use App\Models\Application;
use App\Models\Project;
use App\Models\Environment;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApplicationHttpAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Team $team;
    private Project $project;
    private Environment $environment;
    private Server $server;
    private StandaloneDocker $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
        $this->server = Server::factory()->create(['team_id' => $this->team->id]);
        $this->destination = StandaloneDocker::factory()->create(['server_id' => $this->server->id]);

        // Set the user's current team
        $this->actingAs($this->user);
        $this->user->currentTeam()->associate($this->team);
        $this->user->save();

        // Set instance settings FQDN
        \App\Models\InstanceSettings::factory()->create(['fqdn' => 'http://coolify.test']);
    }

    public function test_creating_new_application_with_http_basic_auth()
    {
        Livewire::test(PublicGitRepository::class, [
            'parameters' => [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
            ],
            'query' => [
                'destination' => $this->destination->uuid,
            ]
        ])
            ->set('repository_url', 'https://example.com/user/public-repo.git')
            ->set('port', 3000)
            ->set('build_pack', 'nixpacks')
            ->set('isStatic', false)
            ->set('git_auth_type', 'http_basic')
            ->set('git_http_username', 'testuser')
            ->set('git_http_password', 'testpass')
            ->call('loadBranch') // Simulate branch check if needed by your submit logic
            ->call('submit')
            ->assertRedirect();

        $this->assertDatabaseHas('applications', [
            'git_repository' => 'example.com/user/public-repo', // Assuming it gets sanitized
            'git_branch' => 'main', // Or whatever default branch is set
            'git_auth_type' => 'http_basic',
            'environment_id' => $this->environment->id,
        ]);

        $application = Application::where('git_repository', 'example.com/user/public-repo')->first();
        $this->assertNotNull($application);
        $this->assertEquals('testuser', $application->git_http_username); // Accessor decrypts
        $this->assertEquals('testpass', $application->git_http_password); // Accessor decrypts

        // Check raw encrypted values
        $this->assertNotEquals('testuser', $application->getAttributes()['git_http_username']);
        $this->assertNotNull($application->getAttributes()['git_http_username']);
        $this->assertNotEquals('testpass', $application->getAttributes()['git_http_password']);
        $this->assertNotNull($application->getAttributes()['git_http_password']);
    }

    public function test_updating_existing_application_to_http_basic_auth()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'git_auth_type' => 'ssh', // Start with SSH
            'private_key_id' => \App\Models\PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
        ]);
        $application->settings()->create(); // Ensure settings relationship exists

        Livewire::test(ApplicationGeneral::class, ['applicationId' => $application->id])
            ->set('application.git_auth_type', 'http_basic')
            ->set('application.git_repository', 'https://new.example.com/user/repo.git')
            ->set('application.git_branch', 'develop')
            ->set('application.git_http_username', 'newuser')
            ->set('application.git_http_password', 'newpass')
            ->call('submit')
            ->assertHasNoErrors();

        $application->refresh();

        $this->assertEquals('http_basic', $application->git_auth_type);
        $this->assertEquals('https://new.example.com/user/repo.git', $application->git_repository);
        $this->assertEquals('develop', $application->git_branch);
        $this->assertEquals('newuser', $application->git_http_username);
        $this->assertEquals('newpass', $application->git_http_password);
        $this->assertNull($application->private_key_id); // Check if old auth specific field is cleared

        // Check raw encrypted values
        $this->assertNotEquals('newuser', $application->getAttributes()['git_http_username']);
        $this->assertNotNull($application->getAttributes()['git_http_username']);
        $this->assertNotEquals('newpass', $application->getAttributes()['git_http_password']);
        $this->assertNotNull($application->getAttributes()['git_http_password']);
    }

    public function test_new_application_form_conditional_rendering()
    {
        Livewire::test(PublicGitRepository::class, [
            'parameters' => [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
            ],
             'query' => [
                'destination' => $this->destination->uuid,
            ]
        ])
            ->assertDontSeeHtml('id="git_http_username"')
            ->assertDontSeeHtml('id="git_http_password"')
            ->set('git_auth_type', 'http_basic')
            ->assertSeeHtml('id="git_http_username"')
            ->assertSeeHtml('id="git_http_password"');
    }

    public function test_existing_application_settings_conditional_rendering()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'git_auth_type' => 'none'
        ]);
         $application->settings()->create();


        Livewire::test(ApplicationGeneral::class, ['applicationId' => $application->id])
            ->assertDontSeeHtml('id="application.git_http_username"')
            ->assertDontSeeHtml('id="application.git_http_password"')
            ->set('application.git_auth_type', 'http_basic')
            ->assertSeeHtml('id="application.git_http_username"')
            ->assertSeeHtml('id="application.git_http_password"')
            ->set('application.git_auth_type', 'ssh')
            ->assertDontSeeHtml('id="application.git_http_username"')
            ->assertDontSeeHtml('id="application.git_http_password"');
            // ->assertSeeHtml('id="application.private_key_id"'); // Assuming SSH key selector has this ID
    }
}
