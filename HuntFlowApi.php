<?php


namespace App\ExternalApis;

use App\Models\RecommendFriend;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use App\Metrics\Metrics;

/**
 * Class HuntFlowApi
 * @package App\ExternalApis
 */
class HuntFlowApi
{
    /**
     * @var ClientInterface
     */
    private $client;
    /**
     * @var int
     */
    private $accountId;

    /**
     * @var Metrics
     */
    private $metric;

    /**
     * HuntFlowApi constructor.
     * @param int $accountId
     * @param Client $client
     */

    public function __construct(int $accountId, Client $client)
    {
        $this->client = $client;
        $this->accountId = $accountId;
        $this->metric = app(Metrics::class);
    }

    /**
     * @param int $page
     * @param bool $opened
     * @return array
     * @throws GuzzleException
     */
    public function getVacancies(int $page = 1, bool $opened = true)
    {
        $query = [
            'page' => $page
        ];

        if ($opened) {
            $query['opened'] = 1;
        }

        $data = [
            'query' => $query
        ];

        $response = $this->sendRequest(
            'GET',
            '/account/' . $this->accountId . '/vacancies/',
            $data
        );

        $this->metric->setRequestCode('/account/' . $this->accountId . '/vacancies/', $response->getStatusCode(), Metrics::NAME_API);

        return $this->decodeResponse($response);
    }

    public function getCompanyStructure()
    {
        $query = [

        ];

        $data = [
            'query' => $query
        ];

        $response = $this->sendRequest(
            'GET',
            '/account/' . $this->accountId . '/all_divisions',
            $data
        );

        $this->metric->setRequestCode('/account/' . $this->accountId . '/all_divisions/', $response->getStatusCode(), Metrics::NAME_API);

        return $this->decodeResponse($response);
    }

    /**
     * @param int $id
     * @return array
     * @throws GuzzleException
     */
    public function getVacancy(int $id)
    {
        $response = $this->sendRequest(
            'GET',
            '/account/' . $this->accountId . '/vacancies/' . $id
        );

        $this->metric->setRequestCode('/account/' . $this->accountId . '/vacancies/', $response->getStatusCode(), Metrics::NAME_API);

        return $this->decodeResponse($response);
    }

    /**
     * @param ResponseInterface|null $response
     * @return array
     */
    protected function decodeResponse(?ResponseInterface $response): array
    {
        if ($response === null) {
            return [];
        }

        $decoded = json_decode((string)$response->getBody(), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return [];
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $data
     * @return ResponseInterface
     * @throws GuzzleException
     */
    private function sendRequest(string $method, string $url, array $data = []): ResponseInterface
    {
        $this->metric->startTime();
        $request = $this->client->request(
            $method,
            $url,
            $data

        );
        $this->metric->setRequestTime($url, Metrics::NAME_API);
        return $request;
    }

    public function addApplicant($data)
    {
        $response = $this->sendRequest(
            'POST',
            '/account/' . $this->accountId . '/applicants/',
            [RequestOptions::JSON => $data]
        );

        return $this->decodeResponse($response);

    }

    public function addApplicantToVacancy($applicant_id, $data)
    {
        $response = $this->sendRequest(
            'POST',
            '/account/' . $this->accountId . '/applicants/' . $applicant_id . '/vacancy',
            [RequestOptions::JSON => $data]
        );

        return $this->decodeResponse($response);
    }

    public function getStatus($applicant_id)
    {
        $response = $this->sendRequest(
            'GET',
            '/account/' . $this->accountId . '/applicants/' . $applicant_id . '/log');

        return $this->decodeResponse($response);

    }

}
