/**
 * AVBA — Normas aplicables al equipo de protección contra caídas.
 *
 * Vive aquí y no dentro de cada página porque la usan tanto el inspector al
 * capturar como Calidad al agregar equipo: teniéndola duplicada, actualizar una
 * revisión en un lado dejaba el otro desfasado.
 */
const ARN_NORMAS = [
  { clave: 'NOM-009-STPS-2011',      titulo: 'Condiciones de seguridad para realizar trabajos en altura' },
  { clave: 'ANSI/ASSP Z359.11-2021', titulo: 'Safety Requirements for Full Body Harnesses' },
  { clave: 'ANSI/ASSP Z359.12-2019', titulo: 'Connecting Components for Personal Fall Arrest Systems' },
  { clave: 'ANSI/ASSP Z359.13-2013', titulo: 'Personal Energy Absorbers and Energy Absorbing Lanyards' },
  { clave: 'ANSI/ASSP Z359.14-2021', titulo: 'Safety Requirements for Self-Retracting Devices' },
  { clave: 'ANSI/ASSP Z359.15-2014', titulo: 'Single Anchor Lifelines and Fall Arresters for Personal Fall Arrest and Rescue Systems' },
  { clave: 'ANSI/ASSP Z359.16-2016', titulo: 'Safety Requirements for Climbing Ladder Fall Arrest Systems' },
  { clave: 'ANSI/ASSP Z359.18-2017', titulo: 'Safety Requirements for Anchorage Connectors for Active Fall Protection Systems' },
  { clave: 'ANSI/ASSP Z359.6-2016',  titulo: 'Specifications and Design Requirements for Active Fall Protection Systems' },
  { clave: 'OSHA 29 CFR 1926.502',   titulo: 'Fall Protection Systems Criteria and Practices' },
  { clave: 'EN 361:2002',            titulo: 'Arneses anticaídas' },
  { clave: 'EN 354:2010',            titulo: 'Elementos de amarre' },
  { clave: 'EN 355:2002',            titulo: 'Absorbedores de energía' },
  { clave: 'EN 360:2023',            titulo: 'Anticaídas retráctiles' },
];
