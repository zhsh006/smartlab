function clampValue(value, minValue, maxValue) {
  if (value < minValue) return minValue;
  if (value > maxValue) return maxValue;
  return value;
}

function channelOd(value) {
  return -log((maxOf(1, value) + 1) / 256);
}

function dabScoreFromRgb(red, green, blue) {
  odR = channelOd(red);
  odG = channelOd(green);
  odB = channelOd(blue);
  dabBasisR = 0.26916687204956063;
  dabBasisG = 0.5682411743268502;
  dabBasisB = 0.777593185920953;
  return clampValue((odR * dabBasisR + odG * dabBasisG + odB * dabBasisB) * 120, 0, 255);
}

function heScoreFromRgb(red, green, blue) {
  odR = channelOd(red);
  odG = channelOd(green);
  odB = channelOd(blue);
  heBasisR = 0.6511078257574492;
  heBasisG = 0.7011930431234068;
  heBasisB = 0.29049426072255424;
  return clampValue((odR * heBasisR + odG * heBasisG + odB * heBasisB) * 120, 0, 255);
}

arg = getArgument();
parts = split(arg, "|");
imagePath = parts[0];
mode = parts[1];
threshold = parseFloat(parts[2]);
heThreshold = parseFloat(parts[3]);
roiSpec = parts[4];

open(imagePath);

width = getWidth();
height = getHeight();
print("IMAGE\t" + width + "\t" + height);

rois = split(roiSpec, ";");
for (r = 0; r < rois.length; r++) {
  roiParts = split(rois[r], ",");
  if (roiParts.length < 5) {
    continue;
  }

  label = roiParts[0];
  x = floor(parseFloat(roiParts[1]));
  y = floor(parseFloat(roiParts[2]));
  w = floor(parseFloat(roiParts[3]));
  h = floor(parseFloat(roiParts[4]));

  if (w < 1 || h < 1) {
    continue;
  }

  pixelCount = 0;
  roughTissueCount = 0;
  roughSignalTotal = 0;
  roughTissueSignalTotal = 0;

  for (yy = y; yy < y + h; yy++) {
    for (xx = x; xx < x + w; xx++) {
      if (xx < 0 || yy < 0 || xx >= width || yy >= height) {
        continue;
      }

      rgb = getPixel(xx, yy);
      red = floor(rgb / 65536);
      green = floor((rgb - red * 65536) / 256);
      blue = rgb - red * 65536 - green * 256;

      brown = red - (0.7 * green + 0.35 * blue);
      if (brown < 0) brown = 0;

      dab = dabScoreFromRgb(red, green, blue);
      he = heScoreFromRgb(red, green, blue);
      luminance = 0.299 * red + 0.587 * green + 0.114 * blue;
      odSum = channelOd(red) + channelOd(green) + channelOd(blue);
      signal = dab;
      tissueSignal = maxOf(dab, he);
      if (mode == "brown") {
        signal = brown;
        tissueSignal = maxOf(brown, he);
      }
      roughTissue = odSum >= 0.12 || luminance <= 235;
      if (roughTissue) {
        roughTissueCount++;
        roughSignalTotal += signal;
        roughTissueSignalTotal += tissueSignal;
      }
      pixelCount++;
    }
  }

  if (roughTissueCount < 1)
    roughTissueCount = 1;
  tissueGate = maxOf(minOf(threshold, heThreshold) * 0.6, (roughTissueSignalTotal / roughTissueCount) * 0.75, 8);
  positiveGate = maxOf(threshold * 0.9, (roughSignalTotal / roughTissueCount), 12);

  positiveCount = 0;
  tissueCount = 0;
  totalSignal = 0;

  for (yy = y; yy < y + h; yy++) {
    for (xx = x; xx < x + w; xx++) {
      if (xx < 0 || yy < 0 || xx >= width || yy >= height) {
        continue;
      }

      rgb = getPixel(xx, yy);
      red = floor(rgb / 65536);
      green = floor((rgb - red * 65536) / 256);
      blue = rgb - red * 65536 - green * 256;

      brown = red - (0.7 * green + 0.35 * blue);
      if (brown < 0) brown = 0;

      dab = dabScoreFromRgb(red, green, blue);
      he = heScoreFromRgb(red, green, blue);
      luminance = 0.299 * red + 0.587 * green + 0.114 * blue;
      odSum = channelOd(red) + channelOd(green) + channelOd(blue);
      signal = dab;
      tissueSignal = maxOf(dab, he);
      if (mode == "brown") {
        signal = brown;
        tissueSignal = maxOf(brown, he);
      }
      roughTissue = odSum >= 0.12 || luminance <= 235;
      isTissue = roughTissue && tissueSignal >= tissueGate;

      if (isTissue) {
        tissueCount++;
      }

      if (signal >= positiveGate && isTissue) {
        positiveCount++;
        totalSignal += signal;
      }
    }
  }

  if (pixelCount < 1)
    pixelCount = 1;
  if (tissueCount > 0)
    roiArea = tissueCount;
  else
    roiArea = pixelCount;
  positiveRatio = 100 * positiveCount / roiArea;
  tissueRatio = 100 * tissueCount / pixelCount;
  if (positiveCount > 0)
    meanSignal = totalSignal / positiveCount;
  else
    meanSignal = 0;

  if (meanSignal >= 96) intensityScore = 3;
  else if (meanSignal >= 64) intensityScore = 2;
  else if (meanSignal >= 24) intensityScore = 1;
  else intensityScore = 0;

  hScore = positiveRatio * intensityScore;
  if (positiveRatio >= 80) percentageCategory = 4;
  else if (positiveRatio >= 50) percentageCategory = 3;
  else if (positiveRatio >= 10) percentageCategory = 2;
  else if (positiveRatio > 0) percentageCategory = 1;
  else percentageCategory = 0;

  irs = percentageCategory * intensityScore;

  print("ROI\t" + label + "\t" + d2s(positiveRatio, 3) + "\t" + intensityScore + "\t" + d2s(hScore, 3) + "\t" + irs + "\t" + d2s(meanSignal, 3) + "\t" + d2s(tissueRatio, 3));
}

run("Quit");
