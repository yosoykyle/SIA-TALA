<?php

namespace App\Actions\Authentication;

use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ManageUserProfilePhoto
{
    public static function fileUploadField(): FileUpload
    {
        return FileUpload::make('photo')
            ->label('Profile photo')
            ->image()
            ->storeFiles(false)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(2048)
            ->required()
            ->helperText('JPG, PNG or WebP · up to 2 MB. Your photo stays private.');
    }

    public function upload(User $actor, mixed $photo): void
    {
        Validator::make(
            ['photo' => $photo],
            ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']],
        )->validate();

        if (! $photo instanceof UploadedFile) {
            throw ValidationException::withMessages(['photo' => 'Choose a valid image file.']);
        }

        $prefix = 'profile-photos/'.$actor->id.'/';
        $path = $photo->store($prefix, ['disk' => 'local', 'visibility' => 'private']);

        if ($path === false || ! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages(['photo' => 'Unable to save your photo. Try again.']);
        }

        try {
            $oldPath = DB::transaction(function () use ($actor, $path): ?string {
                $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $previous = $locked->avatar_path;
                $locked->forceFill(['avatar_path' => $path])->save();

                return $previous;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        if ($oldPath !== $path) {
            $this->deleteManagedFile($oldPath, $prefix);
        }

        $actor->refresh();
    }

    public function remove(User $actor): void
    {
        $prefix = 'profile-photos/'.$actor->id.'/';

        $oldPath = DB::transaction(function () use ($actor): ?string {
            $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->avatar_path;
            $locked->forceFill(['avatar_path' => null])->save();

            return $previous;
        });

        $this->deleteManagedFile($oldPath, $prefix);
        $actor->refresh();
    }

    private function deleteManagedFile(?string $path, string $prefix): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, $prefix) && ! str_contains($normalized, '../')) {
            Storage::disk('local')->delete($normalized);
        }
    }
}
