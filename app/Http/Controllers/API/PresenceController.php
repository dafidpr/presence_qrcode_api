<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Presence;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PresenceController extends BaseController
{
    // Get All Presences
    public function getData(Request $request)
    {
        try {
            setlocale(LC_ALL, 'IND');
            Carbon::setLocale('id');
            $presences = Presence::where(['user_id' => Auth::user()->id])->orderBy('id', 'desc');
            if ($request->limit) {
                $presences = $presences->take($request->limit);
            }
            $presences = $presences->get();
            $presences = $presences->map(function ($presence) {
                $presence->date = Carbon::parse($presence->date)->isoFormat('D MMMM Y');
                return $presence;
            });
            return $this->sendResponse($presences, 'Berhasil mengambil data absensi.');
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), ['trace' => $e->getTrace()], 500);
        }
    }
    // Count Presences
    public function countPresences(Request $request)
    {
        try {
            $maxDays = date('t');
            $attendance = Presence::where(['user_id' => Auth::user()->id, 'type' => 'checkin'])->whereMonth('date', date('m'))->whereYear('date', date('Y'))->get();
            $data = [
                'attendance' => $attendance->count(),
                'absence' => $maxDays - $attendance->count(),
            ];
            return $this->sendResponse($data, 'Berhasil mengambil jumlah absensi.');
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), ['trace' => $e->getTrace()], 500);
        }
    }
    // Get Presence By ID
    public function getPresenceById(Request $request, Presence $presence)
    {
        try {
            if (!$presence) {
                return $this->sendError('Data tidak ditemukan.', [], 404);
            }
            $data = [
                'date' => Carbon::parse($presence->date)->isoFormat('D MMMM Y'),
                'shift' => 'Pukul ' . $presence->shift->start_entry . ' s/d ' . $presence->shift->start_time_exit,
                'type' => $presence->type == 'checkin' ? 'Masuk' : 'Pulang',
                'time_in' => Carbon::parse($presence->time_in)->isoFormat('HH:mm'),
                'location' => $presence->latitude . ', ' . $presence->longitude,
                'description' => $presence->description,
            ];
            return $this->sendResponse($data, 'Berhasil mengambil data absensi.');
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), ['trace' => $e->getTrace()], 500);
        }
    }

    // Create Presence
    public function createPresence(Request $request)
    {
        try {
            $strParse = explode('-', $request->qr_data);
            $type = $strParse[0] == 'datang' ? 'checkin' : 'checkout';
            $date = Carbon::createFromFormat('Y-m-d', $strParse[1] . '-' . $strParse[2] . '-' . $strParse[3]);
            $request->merge(['type' => $type, 'date' => $date->format('Y-m-d')]);
            $shift = Auth::user()->shift()->first();
            if ($type == 'checkin') {
                $checkin = Presence::where(['user_id' => Auth::user()->id, 'type' => 'checkin', 'date' => $date->format('Y-m-d')])->first();
                if (!$checkin) {
                    if (Carbon::now()->format('H:i:s') < $shift->start_time_entry) {
                        $description = 'Masuk | Tepat Waktu';
                    } else {
                        $description = 'Masuk | Terlambat';
                    }
                    $request->merge(['description' => $description]);
                    $presence = $this->_store($request);
                    $data = [
                        'date' => Carbon::parse($presence->date)->isoFormat('D MMMM Y'),
                        'type' => $presence->type,
                        'time_in' => $presence->time_in,
                        'latitude' => $presence->latitude,
                        'longitude' => $presence->longitude,
                        'description' => $presence->description,
                    ];
                    return $this->sendResponse($data, 'Berhasil menyimpan absensi.');
                } else {
                    return $this->sendError('Kamu sudah melakukan absen masuk hari ini.', [], 400);
                }
            } else {
                $checkout = Presence::where(['user_id' => Auth::user()->id, 'type' => 'checkout', 'date' => $date->format('Y-m-d')])->first();
                if (!$checkout) {
                    if (Carbon::now()->format('H:i:s') < $shift->start_exit) {
                        Presence::where(['user_id' => Auth::user()->id, 'date' => Carbon::now()->format('Y-m-d')])->delete();
                        $this->sendResponse(null, 'Kamu dianggap bolos karena pulang lebih awal.');
                    } else {
                        $description = 'Pulang | Tepat Waktu';
                        $request->merge(['description' => $description]);
                        $presence = $this->_store($request);
                        $data = [
                            'date' => Carbon::parse($presence->date)->isoFormat('D MMMM Y'),
                            'type' => $presence->type,
                            'time_in' => $presence->time_in,
                            'latitude' => $presence->latitude,
                            'longitude' => $presence->longitude,
                            'description' => $presence->description,
                        ];
                        return $this->sendResponse($data, 'Berhasil menyimpan absensi.');
                    }
                } else {
                    return $this->sendError('Kamu sudah melakukan absen pulang hari ini.', [], 400);
                }
            }
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), ['trace' => $e->getTrace()], 500);
        }
    }

    private function _store(Request $request)
    {
        $presence = Presence::create([
            'user_id' => Auth::user()->id,
            'shift_id' => Auth::user()->shift_id,
            'date' => $request->date,
            'type' => $request->type,
            'time_in' => Carbon::now()->format('H:i:s'),
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'description' => $request->description,
        ]);
        return $presence;
    }
}
