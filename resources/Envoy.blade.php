@setup
    // Configuration passed from PHP
    $app = $app ?? 'default';
    $strategy = $strategy ?? 'simple';
    $path = $path ?? '/var/www/app';
    $ref = $ref ?? 'main';
    $php = $php ?? 'php';
    $composer = $composer ?? 'composer';

    // Advanced mode settings
    $releaseName = $releaseName ?? date('Ymd_His');
    $releasesPath = $releasesPath ?? $path . '/releases';
    $sharedPath = $sharedPath ?? $path . '/shared';
    $currentLink = $currentLink ?? $path . '/current';
    $releasePath = $releasesPath . '/' . $releaseName;
    $targetReleasePath = $targetReleasePath ?? null;
    $keepReleases = $keepReleases ?? 5;
    $repository = $repository ?? null;

    // Parse shared dirs and files from JSON
    $sharedDirs = isset($sharedDirs) ? json_decode($sharedDirs, true) : ['storage'];
    $sharedFiles = isset($sharedFiles) ? json_decode($sharedFiles, true) : ['.env'];
@endsetup

@servers(['localhost' => '127.0.0.1'])

{{-- ============================================= --}}
{{-- SIMPLE STRATEGY STORIES                       --}}
{{-- ============================================= --}}

@story('staging')
    simple-pull
    simple-install
    simple-migrate
    simple-cache
    simple-restart-queue
@endstory

@story('production')
    simple-maintenance-on
    simple-pull
    simple-install
    simple-clear-cache
    simple-migrate
    simple-cache
    simple-restart-queue
    simple-maintenance-off
@endstory

@story('rollback')
    simple-rollback
    simple-cache
    simple-restart-queue
@endstory

{{-- Simple Strategy Tasks --}}
@task('simple-pull')
    echo "=== Pulling latest code ==="
    cd {{ $path }}
    git fetch origin --prune
    git checkout {{ $ref }}
    git pull origin {{ $ref }} || git reset --hard origin/{{ $ref }}
    echo "Now at: $(git rev-parse --short HEAD)"
@endtask

@task('simple-install')
    echo "=== Installing dependencies ==="
    cd {{ $path }}
    {{ $composer }} install --no-dev --optimize-autoloader --no-interaction
@endtask

@task('simple-migrate')
    echo "=== Running migrations ==="
    cd {{ $path }}
    {{ $php }} artisan migrate --force
@endtask

@task('simple-cache')
    echo "=== Caching configuration ==="
    cd {{ $path }}
    {{ $php }} artisan config:cache
    {{ $php }} artisan route:cache
    {{ $php }} artisan view:cache
@endtask

@task('simple-clear-cache')
    echo "=== Clearing caches ==="
    cd {{ $path }}
    {{ $php }} artisan cache:clear
    {{ $php }} artisan config:clear
    {{ $php }} artisan route:clear
    {{ $php }} artisan view:clear
@endtask

@task('simple-maintenance-on')
    echo "=== Enabling maintenance mode ==="
    cd {{ $path }}
    {{ $php }} artisan down --retry=60 || true
@endtask

@task('simple-maintenance-off')
    echo "=== Disabling maintenance mode ==="
    cd {{ $path }}
    {{ $php }} artisan up
@endtask

@task('simple-restart-queue')
    echo "=== Restarting queue workers ==="
    cd {{ $path }}
    {{ $php }} artisan queue:restart
@endtask

@task('simple-rollback')
    echo "=== Rolling back to previous commit ==="
    cd {{ $path }}
    git checkout HEAD~1
    echo "Rolled back to: $(git rev-parse --short HEAD)"
@endtask

{{-- ============================================= --}}
{{-- ADVANCED STRATEGY STORIES                     --}}
{{-- ============================================= --}}

@story('advanced-staging')
    advanced-prepare
    advanced-clone
    advanced-link-shared
    advanced-install
    advanced-migrate
    advanced-cache
    advanced-activate
    advanced-restart-queue
    advanced-cleanup
@endstory

@story('advanced-production')
    advanced-prepare
    advanced-clone
    advanced-link-shared
    advanced-install
    advanced-clear-cache
    advanced-migrate
    advanced-cache
    advanced-public-storage
    advanced-activate
    advanced-restart-queue
    advanced-cleanup
@endstory

@story('advanced-rollback')
    advanced-rollback-activate
    advanced-restart-queue
@endstory

{{-- Advanced Strategy Tasks --}}
@task('advanced-prepare')
    echo "=== Preparing release: {{ $releaseName }} ==="
    mkdir -p {{ $releasePath }}
    mkdir -p {{ $sharedPath }}/storage/{app/public,framework/{cache,sessions,views},logs}

    # Ensure shared .env exists
    if [ ! -f "{{ $sharedPath }}/.env" ]; then
        echo "WARNING: No .env in shared path. Please create {{ $sharedPath }}/.env"
    fi
@endtask

@task('advanced-clone')
    echo "=== Cloning code to release folder ==="
    @if($repository)
        git clone --depth 1 --branch {{ $ref }} {{ $repository }} {{ $releasePath }}
    @else
        # Copy from current (for same-server deployments)
        if [ -L "{{ $currentLink }}" ]; then
            rsync -a --exclude='.git' --exclude='storage' --exclude='.env' \
                $(readlink -f {{ $currentLink }})/ {{ $releasePath }}/
            cd {{ $releasePath }}
            git fetch origin --prune
            git checkout {{ $ref }}
            git reset --hard {{ $ref }}
        else
            echo "ERROR: No current release and no repository configured"
            exit 1
        fi
    @endif
    echo "Cloned to: {{ $releasePath }}"
@endtask

@task('advanced-link-shared')
    echo "=== Linking shared directories and files ==="

    # Link shared directories
    @foreach($sharedDirs as $dir)
        rm -rf {{ $releasePath }}/{{ $dir }}
        ln -sfn {{ $sharedPath }}/{{ $dir }} {{ $releasePath }}/{{ $dir }}
        echo "Linked: {{ $dir }} -> shared/{{ $dir }}"
    @endforeach

    # Link shared files
    @foreach($sharedFiles as $file)
        rm -f {{ $releasePath }}/{{ $file }}
        ln -sfn {{ $sharedPath }}/{{ $file }} {{ $releasePath }}/{{ $file }}
        echo "Linked: {{ $file }} -> shared/{{ $file }}"
    @endforeach
@endtask

@task('advanced-install')
    echo "=== Installing dependencies ==="
    cd {{ $releasePath }}
    {{ $composer }} install --no-dev --optimize-autoloader --no-interaction
@endtask

@task('advanced-migrate')
    echo "=== Running migrations ==="
    cd {{ $releasePath }}
    {{ $php }} artisan migrate --force
@endtask

@task('advanced-cache')
    echo "=== Caching configuration ==="
    cd {{ $releasePath }}
    {{ $php }} artisan config:cache
    {{ $php }} artisan route:cache
    {{ $php }} artisan view:cache
@endtask

@task('advanced-clear-cache')
    echo "=== Clearing caches ==="
    cd {{ $releasePath }}
    {{ $php }} artisan cache:clear
    {{ $php }} artisan config:clear
    {{ $php }} artisan route:clear
    {{ $php }} artisan view:clear
@endtask

@task('advanced-public-storage')
    echo "=== Creating public storage symlink ==="
    cd {{ $releasePath }}
    rm -f public/storage
    ln -sfn {{ $sharedPath }}/storage/app/public {{ $releasePath }}/public/storage
@endtask

@task('advanced-activate')
    echo "=== Activating release ==="

    # Atomic symlink switch
    ln -sfn {{ $releasePath }} {{ $currentLink }}.new
    mv -f {{ $currentLink }}.new {{ $currentLink }}

    echo "Active release: $(readlink {{ $currentLink }})"
@endtask

@task('advanced-restart-queue')
    echo "=== Restarting queue workers ==="
    cd {{ $currentLink }}
    {{ $php }} artisan queue:restart
@endtask

@task('advanced-cleanup')
    echo "=== Cleaning up old releases (keeping {{ $keepReleases }}) ==="
    cd {{ $releasesPath }}
    # Portable cleanup
    ls -1dt */ | tail -n +{{ $keepReleases + 1 }} | xargs rm -rf
    echo "Remaining releases:"
    ls -1dt */
@endtask

@task('advanced-get-size')
    du -sh {{ $targetPath ?? $path }} 2>/dev/null | cut -f1
@endtask

@task('advanced-rollback-activate')
    echo "=== Rolling back to previous release ==="

    @if($targetReleasePath)
        TARGET={{ $targetReleasePath }}
    @else
        cd {{ $releasesPath }}
        TARGET={{ $releasesPath }}/$(ls -1dt */ | sed -n '2p' | tr -d '/')
    @endif

    if [ ! -d "$TARGET" ]; then
        echo "ERROR: Target release not found: $TARGET"
        exit 1
    fi

    echo "Rolling back to: $TARGET"
    ln -sfn $TARGET {{ $currentLink }}.new
    mv -f {{ $currentLink }}.new {{ $currentLink }}

    echo "Active release: $(readlink {{ $currentLink }})"
@endtask
