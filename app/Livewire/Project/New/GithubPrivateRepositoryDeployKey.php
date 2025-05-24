<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Str;
use Livewire\Component;
use Spatie\Url\Url;

class GithubPrivateRepositoryDeployKey extends Component
{
    public $current_step = 'private_keys';

    public $parameters;

    public $query;

    public $private_keys = [];

    public ?int $private_key_id = null; // Updated type
    public string $auth_type = 'ssh'; // New property
    public ?string $http_username = null; // New property
    public ?string $http_password = null; // New property

    public int $port = 3000;

    public string $type;

    public bool $is_static = false;

    public ?string $publish_directory = null;

    // In case of docker compose
    public ?string $base_directory = null;

    public ?string $docker_compose_location = '/docker-compose.yaml';
    // End of docker compose

    public string $repository_url;

    public string $branch;

    public $build_pack = 'nixpacks';

    public bool $show_is_static = true;

    private object $repository_url_parsed;

    private GithubApp|GitlabApp|string $git_source = 'other';

    private ?string $git_host = null;

    private string $git_repository;

    protected $rules = [
        'repository_url' => 'required|url:http,https,ssh',
        'branch' => 'required|string',
        'port' => 'required|numeric',
        'is_static' => 'required|boolean',
        'publish_directory' => 'nullable|string',
        'build_pack' => 'required|string',
        'auth_type' => 'required|in:ssh,http',
        'http_username' => 'nullable|string|required_if:auth_type,http',
        'http_password' => 'nullable|string|required_if:auth_type,http',
    ];

    protected $validationAttributes = [
        'repository_url' => 'Repository URL',
        'branch' => 'Branch',
        'auth_type' => 'Authentication Type',
        'http_username' => 'HTTP Username',
        'http_password' => 'HTTP Password/Token',
        'port' => 'Port',
        'is_static' => 'Is static',
        'publish_directory' => 'Publish directory',
        'build_pack' => 'Build pack',
    ];

    public function mount()
    {
        if (isDev()) {
            $this->repository_url = 'https://github.com/coollabsio/coolify-examples';
        }
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (isDev()) {
            $this->private_keys = PrivateKey::where('team_id', currentTeam()->id)->get();
        } else {
            $this->private_keys = PrivateKey::where('team_id', currentTeam()->id)->where('id', '!=', 0)->get();
        }
    }

    public function updatedBuildPack()
    {
        if ($this->build_pack === 'nixpacks') {
            $this->show_is_static = true;
            $this->port = 3000;
        } elseif ($this->build_pack === 'static') {
            $this->show_is_static = false;
            $this->is_static = false;
            $this->port = 80;
        } else {
            $this->show_is_static = false;
            $this->is_static = false;
        }
    }

    public function instantSave()
    {
        if ($this->is_static) {
            $this->port = 80;
            $this->publish_directory = '/dist';
        } else {
            $this->port = 3000;
            $this->publish_directory = null;
        }
    }

    public function setPrivateKey($private_key_id)
    {
        $this->private_key_id = $private_key_id;
        $this->auth_type = 'ssh';
        $this->http_username = null;
        $this->http_password = null;
        $this->current_step = 'repository';
    }

    public function setAuthType(string $type)
    {
        $this->auth_type = $type;
        if ($type === 'ssh') {
            $this->http_username = null;
            $this->http_password = null;
            if (is_null($this->private_key_id) && PrivateKey::where('team_id', currentTeam()->id)->count() > 0) {
                $this->current_step = 'private_keys';
            } else if (PrivateKey::where('team_id', currentTeam()->id)->where('id', '!=', 0)->count() === 0) {
                $this->dispatch('error', 'No private keys found. Please add a private key first to use SSH authentication.');
                $this->current_step = 'private_keys';
            } else {
                 $this->current_step = 'repository';
            }
        } elseif ($type === 'http') {
            $this->private_key_id = null;
            $this->current_step = 'repository';
        }
        $this->dispatch('authTypeChanged');
    }

    public function submit()
    {
        $this->validate();
        try {
            $destination_uuid = $this->query['destination'];
            $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
            if (! $destination) {
                $destination = SwarmDocker::where('uuid', $destination_uuid)->first();
            }
            if (! $destination) {
                throw new \Exception('Destination not found. What?!');
            }
            $destination_class = $destination->getMorphClass();

            $this->get_git_source();

            $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
            $environment = $project->load(['environments'])->environments->where('uuid', $this->parameters['environment_uuid'])->first();

            $application_init = [
                'name' => generate_random_name(),
                'git_repository' => $this->git_repository,
                'git_branch' => $this->branch,
                'build_pack' => $this->build_pack,
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
            ];

            if ($this->auth_type === 'http') {
                $application_init['git_auth_type'] = 'http_basic_auth';
                $application_init['git_http_username'] = $this->http_username;
                $application_init['git_http_password'] = $this->http_password;
                $application_init['private_key_id'] = null;
                // For 'other' git_source (which is typical for HTTP basic auth)
                $application_init['source_id'] = null;
                $application_init['source_type'] = null;
            } elseif ($this->auth_type === 'ssh') {
                $application_init['git_auth_type'] = 'ssh_deploy_key';
                $application_init['private_key_id'] = $this->private_key_id;
                $application_init['git_http_username'] = null;
                $application_init['git_http_password'] = null;

                if ($this->git_source instanceof \App\Models\GithubApp || $this->git_source instanceof \App\Models\GitlabApp) {
                    $application_init['source_id'] = $this->git_source->id;
                    $application_init['source_type'] = $this->git_source->getMorphClass();
                } else {
                    // For 'other' SSH sources
                    $application_init['source_id'] = null;
                    $application_init['source_type'] = null;
                }
            }

            if ($this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
                $application_init['health_check_enabled'] = false;
            }
            if ($this->build_pack === 'dockercompose') {
                $application_init['docker_compose_location'] = $this->docker_compose_location;
                $application_init['base_directory'] = $this->base_directory;
            }
            $application = Application::create($application_init);
            $application->settings->is_static = $this->is_static;
            $application->settings->save();

            $fqdn = generateFqdn($destination->server, $application->uuid);
            $application->fqdn = $fqdn;
            $application->name = generate_random_name($application->uuid);
            $application->save();

            return redirect()->route('project.application.configuration', [
                'application_uuid' => $application->uuid,
                'environment_uuid' => $environment->uuid,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function get_git_source()
    {
        if (empty($this->repository_url)) {
            return $this->dispatch('error', 'Repository URL cannot be empty.');
        }
        try {
            $this->repository_url_parsed = Url::fromString($this->repository_url);
        } catch (\Exception $e) {
             return $this->dispatch('error', 'Invalid Repository URL format.');
        }
        $this->git_host = $this->repository_url_parsed->getHost();

        if ($this->auth_type === 'http') {
            $this->git_repository = (string)$this->repository_url_parsed;
            $this->git_source = 'other';
            return;
        }

        // SSH Auth Logic
        $this->git_repository = $this->repository_url_parsed->getSegment(1).'/'.$this->repository_url_parsed->getSegment(2);

        if ($this->git_host === 'github.com') {
            $this->git_source = GithubApp::where('name', 'Public GitHub')->first();
            if ($this->private_key_id) {
                $key = PrivateKey::find($this->private_key_id);
                // If a private key is used, it's not a public GitHub App source unless the key itself is tied to one (which is not the current model)
                // So, if a key is present, it implies a specific setup, not the generic "Public GitHub" app.
                // We'll consider it 'other' for SSH if a key is involved and it's not mapping to a specific registered GitHub App via the key.
                // This part might need refinement based on how PrivateKey relates to specific GithubApp instances.
                // For now, if a key is used, it's not the generic "Public GitHub" source.
                if ($key) { // A private key is selected
                    // Check if this key is specifically for a registered GitHub App (if model supports this)
                    // if ($key->githubApp) { $this->git_source = $key->githubApp; } else ...
                    // Assuming a generic deploy key is used, not tied to a specific GH App instance
                     $this->git_source = 'other';
                }
            }
            return;
        }

        // For non-GitHub SSH (GitLab, other custom hosts)
        if (str($this->repository_url)->startsWith('http')) {
            // Convert HTTP-looking URLs to git@host:path.git for SSH cloning
            // This part might be contentious if user provides HTTP URL but intends to use SSH key for a non-GitHub host
            // For now, assume if auth_type is SSH and URL is HTTP, it's for a system that allows SSH via HTTP-like URLs, or it's a misconfiguration.
            // The most common case for SSH is git@... style URLs.
            $path = ltrim($this->repository_url_parsed->getPath(), '/');
            $this->git_repository = Str::finish("git@{$this->git_host}:{$path}", '.git');
        } else {
            // Assumes it's already an SSH URL like git@custom.com:user/repo.git
            $this->git_repository = $this->repository_url;
        }
        $this->git_source = 'other'; // Default for non-GitHub SSH
        // Similar logic for GitlabApp if a private key can be associated with one
        // if ($this->private_key_id) {
        //    $key = PrivateKey::find($this->private_key_id);
        //    if ($key && $key->gitlabApp) { $this->git_source = $key->gitlabApp; }
        // }
    }
}
