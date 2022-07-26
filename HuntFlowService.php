<?php


namespace App\Services;

use App\ExternalApis\HuntFlowApi;
use App\Models\RecommendFriend;
use App\Models\Vacancy;
use App\Models\VacancyDepartment;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewRecommendFriend;


class HuntFlowService
{
    /**
     * @var HuntFlowApi
     */
    private $api;
    private $vacancies = [];
    private $structure = [];

    public function __construct(HuntFlowApi $api)
    {
        $this->api = $api;
    }

    public function loadVacancies()
    {
        $pageNum = 1;
        try {
            $firstpage = $this->api->getVacancies(1);
            $this->vacancies = array_merge($this->vacancies, $firstpage['items']);
        } catch (GuzzleException $e) {
            \Log::error($e->getMessage());
        }

        if (isset($firstpage) && $firstpage['total'] > 1) {
            for ($i = 2; $i <= $firstpage['total']; $i++) {
                try {
                    $page = $this->api->getVacancies($i);
                    $this->vacancies = array_merge($this->vacancies, $page['items']);
                } catch (GuzzleException $e) {
                    \Log::error($e->getMessage());
                }
            }
        }

        \Log::debug(count($this->vacancies) . ' vacancies loaded');
    }

    public function loadStructure()
    {
        $data = $this->api->getCompanyStructure();
        $this->structure = $data['items'];

    }

    public function processStructure()
    {
      
        $itemsToInsert = [];
        foreach ($this->structure as $item) {
            $newItem = [
                'name' => $item['name'],
                'id' => $item['id'],
                'parent_id' => $item['parent'],
                '_lft' => $item['lft'] ?? 1,
                '_rgt' => $item['rgt'] ?? 1,
                'foreign' => $item['foreign'],
                'removed' => $item['removed'] ?? null,
                'active' => $item['active'],
                'meta' => $item['meta'],
                'deep' => $item['deep'],
                'order' => $item['order'],
            ];
            $itemsToInsert[] = $newItem;
        }
        VacancyDepartment::truncate();
        VacancyDepartment::insert($itemsToInsert);
        VacancyDepartment::fixTree();
    }

    /**
     * @return array
     */
    public function getVacancies(): array
    {
        return $this->vacancies;
    }

    public function processVacancies()
    {
        $huntFlowIdList = Vacancy::select(['huntflow_id'])->pluck('huntflow_id');

        $itemsToInsert = [];
        foreach ($this->vacancies as $loadedVacancy) {
            if (!$huntFlowIdList->contains($loadedVacancy['id']) && $loadedVacancy['account_division']) {
                $now = Carbon::now()->toDateTimeString();
                $item = [
                    'huntflow_id' => $loadedVacancy['id'],
                    'name' => $loadedVacancy['position'],
                    'department' => $loadedVacancy['company'],
                    'money' => $loadedVacancy['money'],
                    'published' => false,
                    'applicants_to_hire' => $loadedVacancy['applicants_to_hire'] ?? 1,
                    'department_id' => $loadedVacancy['account_division'],
                    'state' => $loadedVacancy['state'],
                    'created' => Carbon::parse($loadedVacancy['created'])->toDateTimeString(),
                    'created_at' => $now,
                    'updated_at' => $now
                ];

                $itemsToInsert[] = $item;
            }
        }
        Vacancy::insert($itemsToInsert);
    }


    public function getVacancy(int $id)
    {
        try {
            return [
                'success' => true,
                'data' => $this->api->getVacancy($id)
            ];
        } catch (ClientException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        } catch (GuzzleException $e) {
            \Log::error($e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function addApplicant()
    {
        try {
            $item = RecommendFriend::getNewApplicant();

            if ($item) {
                $id = $item->id;
                $data = [
                    'last_name' => $item['friend_last_name'],
                    'first_name' => $item['friend_first_name'],
                    'phone' => $item['friend_phone'],
                    'email' => $item['friend_email'],
                    'externals' => [0 => ["auth_type" => RecommendFriend::AUTH_TYPE, 'account_source' => RecommendFriend::ACCOUNT_SOURCE]]
                ];

                $applicant_id = $this->api->addApplicant($data);
                RecommendFriend::updateApplicandId($id, $applicant_id['id']);
            }
        } catch (GuzzleException $e) {
            \Log::error($e->getMessage());
        }

    }

    public function addApplicantToVacancy()
    {
        try {
            $item = RecommendFriend::getApplicantToVacancy();
            if ($item) {
                $applicant_id = $item->applicant_id;
                $data = [
                    'vacancy' => $item->huntflow_vacancy_id,
                    'status' => RecommendFriend::APPLICANT_NEW_STATUS,
                    'comment' => 'Рекомендовал(а)' . ' ' . $item->profile_name . ' ' . $item->profile_email . ' ' . $item->profile_phone
                ];

                $response = $this->api->addApplicantToVacancy($applicant_id, $data);

                RecommendFriend::updateStatus($applicant_id, RecommendFriend::APPLICANT_NEW_STATUS);
            }

        } catch (GuzzleException $e) {
            \Log::error($e->getMessage());
        }

    }

    public function updateStatus()
    {
        try {
            foreach (RecommendFriend::getApplicantStatus() as $item) {
                $applicant_id = $item->applicant_id;

                $current_status = $item->status;

                $response = $this->api->getStatus($applicant_id);

                $status = $response['items'][0]['status'];

                if ($current_status != $status) {
                    RecommendFriend::updateStatus($applicant_id, $status);

                    foreach (RecommendFriend::STATUSES as $value) {

                        if ($value['id'] == $status) {
                            RecommendFriend::updateStatusName($applicant_id, $value['name']);

                            foreach (RecommendFriend::getInfToSend($applicant_id) as $info) {
                                Mail::to($info->profile_email)->send(new NewRecommendFriend($info));
                            }
                        }
                    }
                }
            }
        } catch
        (GuzzleException $e) {
            \Log::error($e->getMessage());
        }
    }

    public function changeVacancyStatus()
    {
        try {
            foreach (Vacancy::huntflowIdNotNull() as $value) {
                $state = $this->api->getVacancy($value['huntflow_id']);
                Vacancy::updateVacancyState($value['huntflow_id'], $state['state']);
            }
        } catch
        (GuzzleException $e) {
            \Log::error($e->getMessage());
        }

    }

}
