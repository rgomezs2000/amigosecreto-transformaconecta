@extends('layouts.app')

@section('title', 'Perfiles · Amigo Secreto Inclusivo')
@section('shell-title', 'Perfiles')
@section('shell-eyebrow', 'General')

@section('page')
    @if (session('perfil_status'))
        <div class="alert perfil-alert" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>{{ session('perfil_status') }}</span>
        </div>
    @endif

    <div class="perfil-page">
        <section class="perfil-card" aria-labelledby="perfil-title">
            <div class="perfil-card__header">
                <div>
                    <p class="module-eyebrow">Mi cuenta</p>
                    <h2 class="perfil-card__title" id="perfil-title">{{ $user->name }}</h2>
                    <p class="perfil-card__subtitle">
                        Perfil: {{ $perfil?->name ?? 'Sin perfil' }} · Rol: {{ $user->role?->label() }}
                    </p>
                </div>

                <div class="perfil-actions">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-perfil">
                        <i class="fa-solid fa-user-pen" aria-hidden="true"></i>
                        <span>Modificar perfil</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal-clave">
                        <i class="fa-solid fa-key" aria-hidden="true"></i>
                        <span>Cambiar contraseña</span>
                    </button>
                </div>
            </div>

            <dl class="perfil-data">
                <div class="perfil-data__item">
                    <dt>Nombre</dt>
                    <dd>{{ $user->name }}</dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Correo</dt>
                    <dd>{{ $user->email }}</dd>
                </div>
                <div class="perfil-data__item">
                    <dt>Login</dt>
                    <dd>{{ $user->username }}</dd>
                </div>
            </dl>
        </section>

        <section class="perfil-card" aria-labelledby="perfil-access-title">
            <div class="perfil-card__header">
                <div>
                    <p class="module-eyebrow">Accesos</p>
                    <h2 class="perfil-card__title" id="perfil-access-title">Rol, módulos y permisos</h2>
                    <p class="perfil-card__subtitle">
                        Solo el administrador puede modificar estos accesos.
                    </p>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table perfil-table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Módulo</th>
                            <th scope="col">Grupo</th>
                            <th scope="col">Permisos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($modules as $item)
                            <tr>
                                <td>
                                    <span class="perfil-module">
                                        <i class="fa-solid {{ $item['module']->icon }}" aria-hidden="true"></i>
                                        {{ $item['module']->name }}
                                    </span>
                                </td>
                                <td>{{ $item['module']->group }}</td>
                                <td>
                                    @forelse ($item['permissions'] as $permission)
                                        <span class="badge perfil-badge">{{ $permission->name }}</span>
                                    @empty
                                        <span class="text-muted">Sin permisos</span>
                                    @endforelse
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-muted">No tienes módulos asignados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- Modificar perfil --}}
    <div class="modal fade" id="modal-perfil" tabindex="-1" aria-labelledby="modal-perfil-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="form-perfil" action="{{ route('perfil.cambiar') }}" method="post" novalidate
                      data-login-url="{{ route('perfil.login-existe') }}">
                    @csrf

                    <div class="modal-header">
                        <h2 class="modal-title" id="modal-perfil-title">Modificar perfil</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>

                    <div class="modal-body">
                        <div class="field">
                            <label for="perfil-name" class="form-label">Nombre</label>
                            <input type="text" class="form-control" id="perfil-name" name="name"
                                   value="{{ old('name', $user->name) }}" required
                                   autocomplete="name" autocapitalize="words">
                            <p class="field-message" data-field-message="name" role="status" aria-live="polite"></p>
                        </div>

                        <div class="field">
                            <label for="perfil-email" class="form-label">Correo</label>
                            <input type="email" class="form-control" id="perfil-email" name="email"
                                   value="{{ old('email', $user->email) }}" required
                                   autocomplete="email" autocapitalize="none" spellcheck="false">
                            <p class="field-message" data-field-message="email" role="status" aria-live="polite"></p>
                        </div>

                        <div class="field">
                            <label for="perfil-username" class="form-label">Login</label>
                            <input type="text" class="form-control" id="perfil-username" name="username"
                                   value="{{ old('username', $user->username) }}"
                                   data-current="{{ $user->username }}"
                                   required autocomplete="username" autocapitalize="none" spellcheck="false"
                                   aria-describedby="perfil-username-help">
                            <p id="perfil-username-help" class="form-text">
                                Así entrarás al sistema. Debe ser único.
                            </p>
                            <p class="field-message" data-field-message="username"
                               id="perfil-username-status" role="status" aria-live="polite"></p>
                        </div>

                        <p id="perfil-form-status" class="form-status" role="status" aria-live="polite"></p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" id="perfil-submit">
                            Guardar cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Cambiar contraseña --}}
    <div class="modal fade" id="modal-clave" tabindex="-1" aria-labelledby="modal-clave-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="form-clave" action="{{ route('perfil.clave') }}" method="post" novalidate>
                    @csrf

                    <div class="modal-header">
                        <h2 class="modal-title" id="modal-clave-title">Cambiar contraseña</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>

                    <div class="modal-body">
                        <div class="field">
                            <label for="perfil-current" class="form-label">Clave actual</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="perfil-current"
                                       name="current_password" required autocomplete="current-password">
                                <button type="button" class="btn btn-outline-secondary password-toggle"
                                        data-toggle-password data-target="#perfil-current"
                                        aria-label="Mantén pulsado para ver la clave"
                                        title="Mantén pulsado para ver la clave">
                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="field-message" data-field-message="current_password"
                               role="status" aria-live="polite"></p>
                        </div>

                        <div class="field">
                            <label for="perfil-password" class="form-label">Nueva clave</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="perfil-password"
                                       name="password" required autocomplete="new-password" minlength="10"
                                       aria-describedby="perfil-password-help">
                                <button type="button" class="btn btn-outline-secondary password-toggle"
                                        data-toggle-password data-target="#perfil-password"
                                        aria-label="Mantén pulsado para ver la clave"
                                        title="Mantén pulsado para ver la clave">
                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p id="perfil-password-help" class="form-text">
                                Mínimo 10 caracteres. Puedes usar mayúsculas, minúsculas, números y signos.
                            </p>
                            <p class="field-message" data-field-message="password"
                               role="status" aria-live="polite"></p>
                        </div>

                        <div class="field">
                            <label for="perfil-password-confirm" class="form-label">Confirmar nueva clave</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="perfil-password-confirm"
                                       name="password_confirmation" required autocomplete="new-password" minlength="10">
                                <button type="button" class="btn btn-outline-secondary password-toggle"
                                        data-toggle-password data-target="#perfil-password-confirm"
                                        aria-label="Mantén pulsado para ver la clave"
                                        title="Mantén pulsado para ver la clave">
                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="field-message" data-field-message="password_confirmation"
                               role="status" aria-live="polite"></p>
                        </div>

                        <p id="clave-form-status" class="form-status" role="status" aria-live="polite"></p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" id="clave-submit">
                            Cambiar contraseña
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
