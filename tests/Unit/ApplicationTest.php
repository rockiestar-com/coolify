<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployment_type_returns_http_basic_auth()
    {
        $application = Application::factory()->create([
            'git_auth_type' => 'http_basic',
        ]);
        $this->assertEquals('http_basic_auth', $application->deploymentType());
    }

    public function test_generate_git_import_commands_for_http_basic_auth()
    {
        $application = Application::factory()->create([
            'git_repository' => 'https://example.com/user/repo.git',
            'git_branch' => 'main',
            'git_auth_type' => 'http_basic',
            'git_http_username' => 'testuser',
            'git_http_password' => 'testpass',
        ]);

        $commandsResult = $application->generateGitImportCommands('some_uuid');
        $this->assertStringContainsString('https://testuser:testpass@example.com/user/repo.git', $commandsResult['fullRepoUrl']);
        $this->assertStringContainsString('https://testuser:testpass@example.com/user/repo.git', $commandsResult['commands']);
    }

    public function test_generate_git_ls_remote_commands_for_http_basic_auth()
    {
        $application = Application::factory()->create([
            'git_repository' => 'https://example.com/user/repo.git',
            'git_branch' => 'main',
            'git_auth_type' => 'http_basic',
            'git_http_username' => 'testuser',
            'git_http_password' => 'testpass',
        ]);

        $commandsResult = $application->generateGitLsRemoteCommands('some_uuid');
        $this->assertStringContainsString('https://testuser:testpass@example.com/user/repo.git', $commandsResult['fullRepoUrl']);
        $this->assertStringContainsString('https://testuser:testpass@example.com/user/repo.git', $commandsResult['commands']);
    }

    public function test_git_credentials_are_encrypted_and_decrypted()
    {
        $username = 'testuser';
        $password = 'testpassword';

        $application = Application::factory()->create([
            'git_auth_type' => 'http_basic',
            'git_http_username' => $username,
            'git_http_password' => $password,
        ]);

        // Refresh model from database to ensure attributes are processed by mutators/casts
        $application->refresh();

        // Check raw attributes to see if they are encrypted (i.e., different from original)
        // Note: direct comparison for encryption is tricky as output varies. Check not equal.
        $this->assertNotEquals($username, $application->getAttributes()['git_http_username']);
        $this->assertNotNull($application->getAttributes()['git_http_username']);


        $this->assertNotEquals($password, $application->getAttributes()['git_http_password']);
        $this->assertNotNull($application->getAttributes()['git_http_password']);


        // Check if decrypted values are correct
        $this->assertEquals($username, $application->git_http_username);
        $this->assertEquals($password, $application->git_http_password);
    }

    // Test other deployment types for good measure
    public function test_deployment_type_returns_deploy_key_for_ssh()
    {
        $privateKey = PrivateKey::factory()->create();
        $application = Application::factory()->create([
            'git_auth_type' => 'ssh',
            'private_key_id' => $privateKey->id,
        ]);
        $this->assertEquals('deploy_key', $application->deploymentType());
    }

    public function test_deployment_type_returns_source_for_github_app()
    {
        $githubApp = GithubApp::factory()->create();
        $application = Application::factory()->create([
            'git_auth_type' => 'github_app',
            'source_id' => $githubApp->id,
            'source_type' => $githubApp->getMorphClass(),
        ]);
        $this->assertEquals('source', $application->deploymentType());
    }

     public function test_deployment_type_returns_other_for_none_auth_type()
    {
        $application = Application::factory()->create([
            'git_auth_type' => 'none',
            'private_key_id' => null,
            'source_id' => null,
        ]);
        $this->assertEquals('other', $application->deploymentType());
    }
}
