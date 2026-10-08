import OzonDeliveryDetail from './details/OzonDeliveryDetail';
import SiftDeliveryDetail from './details/SiftDeliveryDetail';
import SpeedafDeliveryDetail from './details/SpeedafDeliveryDetail';

/**
 * Optional per-carrier detail under the shared block. A carrier with no entry
 * uses the generic fallback (label, tracking, status, actions from the server).
 */
const carrierDetails = {
    ozon: OzonDeliveryDetail,
    sift: SiftDeliveryDetail,
    speedaf: SpeedafDeliveryDetail,
};

export function detailFor(key) {
    return (key && carrierDetails[key]) || null;
}
