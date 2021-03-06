<?php

/*
 * Copyright © 2017 Jesse Nicholson
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at http://mozilla.org/MPL/2.0/.
 */

namespace App\Http\Controllers;

use Validator;
use App\User;
use App\Group;
use App\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\DeactivationRequest;
use App\AppUserActivation;

class UserController extends Controller {

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return User::with(['group', 'roles'])->get();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create() {
        // No forms here kids.
        return response('', 405);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request) {
        $this->validate($request, [
            'name' => 'required',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|same:password_verify',
            'role_id' => 'required|exists:roles,id'
        ]);

        $input = $request->only(['name', 'email', 'password', 'role_id', 'group_id']);
        $input['password'] = Hash::make($input['password']);

        $user = User::create($input);

        $suppliedRoleId = $request->input('role_id');
        $suppliedRole = Role::where('id', $suppliedRoleId)->first();
        $user->attachRole($suppliedRole);

        return response('', 204);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id) {
        return User::where('id', $id)->get();
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id) {
        // There is no form, son.
        return response('', 405);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id) {

        // The javascript side/admin UI will not send
        // password or password_verify unless they are
        // intentionally trying to change a user's password.

        $inclPassword = false;

        if ($request->has('password_verify') && $request->has('password')) {
            $this->validate($request, [
                'name' => 'required',
                'email' => 'required',
                'password' => 'required|same:password_verify'
            ]);

            $inclPassword = true;
        } else {
            $this->validate($request, [
                'name' => 'required',
                'email' => 'required'
            ]);
        }

        $input = $request->except(['password', 'password_verify', 'role_id']);

        if ($inclPassword) {
            $pInput = $request->only(['password', 'password_verify']);
            $input['password'] = bcrypt($pInput['password']);
        }

        User::where('id', $id)->update($input);

        if ($request->has('role_id')) {

            $this->validate($request, [
                'role_id' => 'required|exists:roles,id'
            ]);

            $suppliedRoleId = $request->input('role_id');
            $suppliedRole = Role::where('id', $suppliedRoleId)->first();

            $suppliedUser = User::where('id', $id)->first();
            if (!$suppliedUser->hasRole($suppliedRole)) {
                $suppliedUser->detachRoles();
                $suppliedUser->attachRole($suppliedRole);
            }
        }

        return response('', 204);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id) {

        $user = User::where('id', $id)->first();
        if (!is_null($user)) {
            
            $user->detachRoles();
            
            $user->delete();
        }

        return response('', 204);
    }

    /**
     * Get information about the currently applied user data. This includes 
     * filter rules and configuration data.
     *
     * @return \Illuminate\Http\Response
     */
    public function checkUserData(Request $request) {
        $thisUser = \Auth::user();

        $userGroup = $thisUser->group()->first();
        if (!is_null($userGroup)) {
            if (!is_null($userGroup->data_sha1)) {
                return $userGroup->data_sha1;
            }
        }

        return response('', 204);
    }

    /**
     * Request the current user data. This includes filter rules and 
     * configuration data.
     *
     * @return \Illuminate\Http\Response
     */
    public function getUserData(Request $request) {
        $thisUser = \Auth::user();

        $userGroup = $thisUser->group()->first();
        if (!is_null($userGroup)) {
            $groupDataPayloadPath = $userGroup->getGroupDataPayloadPath();
            if (file_exists($groupDataPayloadPath) && filesize($groupDataPayloadPath) > 0) {
                return response()->download($groupDataPayloadPath);
            }
        }

        return response('', 204);
    }

    /**
     * The current authenticated user is requesting an app deactivation. 
     * 
     * @return \Illuminate\Http\Response
     */
    public function getCanUserDeactivate(Request $request) {

        $validator = Validator::make($request->all(), [
                    'identifier' => 'required',
                    'device_id' => 'required'
        ]);

        if (!$validator->fails()) {
            $thisUser = \Auth::user();

            $reqArgs = $request->only(['identifier', 'device_id']);

            $reqArgs['user_id'] = $thisUser->id;

            $deactivateRequest = DeactivationRequest::firstOrCreate($reqArgs);

            if ($deactivateRequest->granted == true) {
                // Clean up the deactivation request.
                $deactivateRequest->delete();

                // Remove this user's registration, since they're being
                // granted an uninstall/removal.
                AppUserActivation::where($reqArgs)->delete();

                return response('', 204);
            }
        }

        return response('', 401);
    }

    /**
     * Handles when user is requesting their license terms.
     * @param Request $request
     */
    public function getUserTerms(Request $request) {

        $userLicensePath = resource_path() . DIRECTORY_SEPARATOR . 'UserLicense.txt';

        if (file_exists($userLicensePath) && filesize($userLicensePath) > 0) {
            return response()->download($userLicensePath);
        }
        return response('', 500);
    }

}
