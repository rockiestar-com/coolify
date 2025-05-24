<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">Deploy any public or private Git repositories via HTTP/S with Basic Authentication (Username/Password or Token).</div>
    <div class="flex flex-col pt-4">
        @if ($current_step === 'repository')
            <h2 class="pb-4">Repository Details</h2>
            <form class="flex flex-col gap-2 pt-2" wire:submit='submit'>
                <x-forms.input id="repository_url" required label="Repository URL (HTTP/S)" helper="Must be an HTTP or HTTPS URL." />
                <x-forms.input id="http_username" label="Username (optional)" helper="Username for HTTP basic authentication. Leave blank if using a token as password or for anonymous public repos (though 'Public Git Repository' is better for those)." />
                <x-forms.input type="password" id="http_password" label="Password / Token (optional)" helper="Password or Personal Access Token for HTTP basic authentication." />
                <div class="flex gap-2">
                    <x-forms.input id="branch" required label="Branch" />
                    <x-forms.select wire:model.live="build_pack" label="Build Pack" required>
                        <option value="nixpacks">Nixpacks</option>
                        <option value="static">Static</option>
                        <option value="dockerfile">Dockerfile</option>
                        <option value="dockercompose">Docker Compose</option>
                    </x-forms.select>
                    @if ($is_static)
                        <x-forms.input id="publish_directory" required label="Publish Directory" />
                    @endif
                </div>
                @if ($build_pack === 'dockercompose')
                    <x-forms.input placeholder="/" wire:model.blur="base_directory" label="Base Directory"
                        helper="Directory to use as root. Useful for monorepos." />
                    <x-forms.input placeholder="/docker-compose.yaml" id="docker_compose_location"
                        label="Docker Compose Location"
                        helper="It is calculated together with the Base Directory:<br><span class='dark:text-warning'>{{ Str::start($base_directory . $docker_compose_location, '/') }}</span>" />
                    Compose file location in your repository:<span
                        class='dark:text-warning'>{{ Str::start($base_directory . $docker_compose_location, '/') }}</span>
                @endif
                @if ($show_is_static)
                    <x-forms.input type="number" required id="port" label="Port" :readonly="$is_static || $build_pack === 'static'" />
                    <div class="w-52">
                        <x-forms.checkbox instantSave id="is_static" label="Is it a static site?"
                            helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                    </div>
                @endif
                <x-forms.button type="submit" class="mt-4">
                    Continue
                </x-forms.button>
            </form>
        @endif
    </div>
</div>
